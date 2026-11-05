<?php

declare(strict_types=1);

namespace Modules\Integration\Application\Services;

use Illuminate\Validation\ValidationException;

/**
 * Stops us from being turned into a way to reach the inside of our own network: a vendor's callback address
 * must be https, and must not point at a private, loopback or link-local address (checked at the moment of
 * sending as well as when it is saved, because a name can be re-pointed afterwards).
 */
final class WebhookUrlGuard
{
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw $this->fail('Alamat callback tidak valid.');
        }

        $allowInsecure = (bool) config('integration.allow_insecure_webhooks');

        if ($scheme !== 'https' && ! ($allowInsecure && $scheme === 'http')) {
            throw $this->fail('Alamat callback harus memakai https.');
        }

        if ($allowInsecure) {
            return;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            throw $this->fail('Nama host callback tidak dapat ditemukan.');
        }

        foreach ($addresses as $address) {
            $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            if ($public === false) {
                throw $this->fail('Alamat callback mengarah ke jaringan internal dan ditolak.');
            }
        }
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['webhook_url' => $message]);
    }
}
