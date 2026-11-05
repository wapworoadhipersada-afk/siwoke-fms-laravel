<?php

declare(strict_types=1);

namespace Modules\Integration\Application\Services;

use Modules\Integration\Domain\Models\IntegrationLog;

/**
 * Writes the record of a call. Credentials never reach it: signatures, keys and cookies are dropped from the
 * headers, and long bodies are cut so one huge request cannot fill the table.
 */
final class LogRecorder
{
    private const MAX_BODY = 20_000;

    private const DROPPED_HEADERS = ['authorization', 'cookie', 'x-signature', 'x-nonce', 'x-fms-signature', 'proxy-authorization'];

    /**
     * @param  array<string, mixed>  $headers
     */
    public function record(
        string $direction,
        string $method,
        string $path,
        array $headers,
        ?string $requestBody,
        ?int $status,
        ?string $responseBody,
        string $correlationId,
        ?int $clientId = null,
        ?int $vendorId = null,
        ?int $ticketId = null,
        ?int $durationMs = null,
        ?string $ip = null,
        ?string $error = null,
    ): IntegrationLog {
        return IntegrationLog::query()->create([
            'direction' => $direction,
            'client_id' => $clientId,
            'vendor_id' => $vendorId,
            'method' => strtoupper($method),
            'path' => mb_substr($path, 0, 300),
            'status_code' => $status,
            'request_headers' => $this->headers($headers),
            'request_body' => $this->cut($requestBody),
            'response_body' => $this->cut($responseBody),
            'duration_ms' => $durationMs,
            'correlation_id' => $correlationId,
            'ticket_id' => $ticketId,
            'ip' => $ip,
            'error' => $error === null ? null : mb_substr($error, 0, 2000),
        ]);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        $kept = [];

        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::DROPPED_HEADERS, true)) {
                continue;
            }

            $kept[(string) $name] = mb_substr(is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value, 0, 300);
        }

        return $kept;
    }

    private function cut(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        // Keep the text valid UTF-8, or the database refuses it and the log is lost.
        $clean = mb_convert_encoding($body, 'UTF-8', 'UTF-8');

        return mb_strlen($clean) > self::MAX_BODY ? mb_substr($clean, 0, self::MAX_BODY).'…[dipotong]' : $clean;
    }
}
