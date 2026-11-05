<?php

declare(strict_types=1);

namespace Modules\Integration\Http\Api\V1\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Integration\Application\Services\LogRecorder;
use Modules\Integration\Application\Services\Signature;
use Modules\Integration\Domain\Models\IntegrationClient;
use Modules\Shared\Http\ProblemException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only if it is signed by a known, active vendor client. Every failure to prove who
 * is calling answers the same generic 401, so a caller learns nothing about which part was wrong; the real
 * reason goes only into our own log. After the answer is sent, `terminate` records the whole call.
 */
final class VerifyVendorRequest
{
    public function __construct(private readonly LogRecorder $logs) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('integration.started', microtime(true));
        $request->attributes->set('integration.correlation', (string) Str::uuid());

        $keyId = (string) $request->header('X-Key-Id');
        $timestamp = (string) $request->header('X-Timestamp');
        $nonce = (string) $request->header('X-Nonce');
        $signature = (string) $request->header('X-Signature');

        $client = $keyId === '' ? null : IntegrationClient::query()
            ->where('key_id', $keyId)
            ->where('is_active', true)
            ->whereHas('vendor', fn ($query) => $query->where('is_active', true))
            ->first();

        $reason = $this->unauthenticatedReason($request, $client, $timestamp, $nonce, $signature);

        if ($reason !== null) {
            $request->attributes->set('integration.error', $reason);

            throw ProblemException::unauthenticated('invalid_credentials', 'Permintaan tidak dapat diautentikasi.');
        }

        assert($client instanceof IntegrationClient);

        if (! $this->ipAllowed($request, $client)) {
            $request->attributes->set('integration.client', $client);
            $request->attributes->set('integration.error', 'ip_not_allowed');

            throw ProblemException::forbidden('ip_not_allowed', 'Alamat IP Anda tidak diizinkan.');
        }

        $request->attributes->set('integration.client', $client);

        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', (string) $request->attributes->get('integration.correlation'));

        return $response;
    }

    /**
     * Runs after the response (including a rendered error) has been produced.
     */
    public function terminate(Request $request, Response $response): void
    {
        $client = $request->attributes->get('integration.client');
        $started = (float) $request->attributes->get('integration.started', microtime(true));
        $ticketId = $request->attributes->get('integration.ticket_id');

        $this->logs->record(
            direction: 'in',
            method: $request->method(),
            path: $request->getRequestUri(),
            headers: $request->headers->all(),
            requestBody: $request->getContent(),
            status: $response->getStatusCode(),
            responseBody: $response->getContent() === false ? null : $response->getContent(),
            correlationId: (string) $request->attributes->get('integration.correlation', Str::uuid()),
            clientId: $client instanceof IntegrationClient ? $client->id : null,
            vendorId: $client instanceof IntegrationClient ? $client->vendor_id : null,
            ticketId: is_int($ticketId) ? $ticketId : null,
            durationMs: (int) round((microtime(true) - $started) * 1000),
            ip: $request->ip(),
            error: is_string($request->attributes->get('integration.error')) ? $request->attributes->get('integration.error') : null,
        );
    }

    /**
     * The first thing wrong with the request's proof of identity, or null when it is good.
     */
    private function unauthenticatedReason(Request $request, ?IntegrationClient $client, string $timestamp, string $nonce, string $signature): ?string
    {
        if (! $client instanceof IntegrationClient) {
            return 'unknown_or_inactive_key';
        }

        if ($timestamp === '' || $nonce === '' || $signature === '' || ! ctype_digit($timestamp) || strlen($nonce) < 8 || strlen($nonce) > 80) {
            return 'missing_or_malformed_headers';
        }

        $expected = Signature::sign($client->secret, $timestamp, $nonce, $request->method(), $request->getRequestUri(), $request->getContent());

        // The signature is checked before anything else is remembered, so a stranger cannot use up nonces.
        if (! Signature::matches($expected, $signature)) {
            return 'bad_signature';
        }

        if (abs(time() - (int) $timestamp) > (int) config('integration.timestamp_tolerance_seconds')) {
            return 'stale_timestamp';
        }

        $window = (int) config('integration.timestamp_tolerance_seconds') * 2;

        if (! Cache::add("integration:nonce:{$client->id}:{$nonce}", 1, $window)) {
            return 'replayed_nonce';
        }

        return null;
    }

    private function ipAllowed(Request $request, IntegrationClient $client): bool
    {
        if ($client->allowed_ips === []) {
            return true;
        }

        return IpUtils::checkIp((string) $request->ip(), $client->allowed_ips);
    }
}
