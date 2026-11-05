<?php

declare(strict_types=1);

namespace Modules\Integration\Application\Services;

/**
 * The signature scheme used in both directions. The sender signs
 *
 *   timestamp \n nonce \n METHOD \n request-uri \n sha256(body)
 *
 * with HMAC-SHA256 and the shared secret. The secret itself never travels; the timestamp limits how long a
 * captured request stays usable, and the nonce makes each one usable once.
 */
final class Signature
{
    public static function sign(string $secret, int|string $timestamp, string $nonce, string $method, string $uri, string $body): string
    {
        $canonical = implode("\n", [$timestamp, $nonce, strtoupper($method), $uri, hash('sha256', $body)]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    public static function matches(string $expected, string $given): bool
    {
        return hash_equals($expected, strtolower($given));
    }
}
