<?php

declare(strict_types=1);

namespace Modules\Integration\Application\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integration\Application\Services\LogRecorder;
use Modules\Integration\Application\Services\Signature;
use Modules\Integration\Application\Services\WebhookUrlGuard;
use Modules\Integration\Domain\Models\IntegrationClient;
use Modules\Integration\Domain\Models\OutboundEvent;
use Throwable;

/**
 * Tells one vendor about one status change. The queue only wakes this up; whether and when to retry is decided
 * here and stored on the event, so a restart or a lost job never loses the promise to deliver.
 */
final class DeliverOutboundEvent implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $outboundEventId) {}

    public function handle(LogRecorder $logs, WebhookUrlGuard $guard): void
    {
        DB::transaction(function () use ($logs, $guard): void {
            // Locked, so two workers woken for the same event cannot both call the vendor.
            $event = OutboundEvent::query()->whereKey($this->outboundEventId)->lockForUpdate()->first();

            if (! $event instanceof OutboundEvent || $event->status !== OutboundEvent::PENDING) {
                return;
            }

            if ($event->next_attempt_at !== null && $event->next_attempt_at->isFuture()) {
                return;
            }

            $client = IntegrationClient::query()->find($event->client_id);

            if (! $client instanceof IntegrationClient || ! $client->is_active || $client->webhook_url === null || $client->webhook_secret === null) {
                $event->forceFill(['status' => OutboundEvent::DEAD, 'last_error' => 'Klien tidak aktif atau callback belum diatur.'])->save();

                return;
            }

            $this->send($event, $client, $logs, $guard);
        });
    }

    private function send(OutboundEvent $event, IntegrationClient $client, LogRecorder $logs, WebhookUrlGuard $guard): void
    {
        $url = (string) $client->webhook_url;
        $body = (string) json_encode([...$event->payload, 'event_id' => $event->ulid], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $nonce = (string) Str::uuid();
        $uri = (string) parse_url($url, PHP_URL_PATH).(parse_url($url, PHP_URL_QUERY) ? '?'.parse_url($url, PHP_URL_QUERY) : '');
        $headers = [
            'Content-Type' => 'application/json',
            'X-Key-Id' => $client->key_id,
            'X-Timestamp' => (string) $timestamp,
            'X-Nonce' => $nonce,
            'X-Event-Id' => $event->ulid,
            'X-Signature' => Signature::sign((string) $client->webhook_secret, $timestamp, $nonce, 'POST', $uri === '' ? '/' : $uri, $body),
        ];

        $started = microtime(true);
        $status = null;
        $response = null;
        $error = null;

        try {
            $guard->assertSafe($url);

            // No redirects: a callback that bounces us elsewhere is not one we agreed to call.
            $result = Http::withHeaders($headers)
                ->withOptions(['allow_redirects' => false])
                ->timeout((int) config('integration.webhook_timeout_seconds'))
                ->withBody($body, 'application/json')
                ->post($url);

            $status = $result->status();
            $response = $result->body();

            if (! $result->successful()) {
                $error = "Vendor menjawab HTTP {$status}.";
            }
        } catch (ValidationException $exception) {
            $error = (string) collect($exception->errors())->flatten()->first();
        } catch (Throwable $exception) {
            $error = 'Gagal menghubungi vendor: '.class_basename($exception);
        }

        $logs->record(
            direction: 'out',
            method: 'POST',
            path: (string) preg_replace('/\?.*$/', '', $url),
            headers: $headers,
            requestBody: $body,
            status: $status,
            responseBody: $response,
            correlationId: (string) Str::uuid(),
            clientId: $client->id,
            vendorId: $client->vendor_id,
            ticketId: $event->ticket_id,
            durationMs: (int) round((microtime(true) - $started) * 1000),
            error: $error,
        );

        $attempts = $event->attempts + 1;

        if ($error === null) {
            $event->forceFill(['status' => OutboundEvent::SENT, 'attempts' => $attempts, 'sent_at' => now(), 'next_attempt_at' => null, 'last_error' => null])->save();

            return;
        }

        $schedule = array_values((array) config('integration.retry_backoff_seconds'));

        if ($attempts > count($schedule)) {
            $event->forceFill(['status' => OutboundEvent::DEAD, 'attempts' => $attempts, 'next_attempt_at' => null, 'last_error' => $error])->save();

            return;
        }

        $event->forceFill(['attempts' => $attempts, 'next_attempt_at' => now()->addSeconds((int) $schedule[$attempts - 1]), 'last_error' => $error])->save();
    }
}
