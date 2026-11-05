<?php

declare(strict_types=1);

namespace Modules\Integration\Application\Actions;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Integration\Application\Services\WebhookUrlGuard;
use Modules\Integration\Domain\Models\IntegrationClient;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Support\Audit;

/**
 * Registers a vendor system that may call us. The secrets are made here and returned once; only the encrypted
 * copy is kept, so nobody (including us) can read them back later. Lose one and the answer is to rotate it.
 */
final class CreateIntegrationClient
{
    public function __construct(private readonly WebhookUrlGuard $webhooks) {}

    /**
     * @param  list<string>  $allowedIps
     * @return array{client: IntegrationClient, secret: string, webhook_secret: string|null}
     */
    public function handle(Vendor $vendor, string $name, array $allowedIps, ?string $webhookUrl, User $actor): array
    {
        $webhookUrl = $webhookUrl !== null && trim($webhookUrl) !== '' ? trim($webhookUrl) : null;

        if ($webhookUrl !== null) {
            $this->webhooks->assertSafe($webhookUrl);
        }

        $secret = Str::random(48);
        $webhookSecret = $webhookUrl === null ? null : Str::random(48);

        $client = new IntegrationClient([
            'vendor_id' => $vendor->id,
            'name' => trim($name),
            'key_id' => 'fms_'.Str::lower(Str::random(20)),
            'secret' => $secret,
            'allowed_ips' => array_values($allowedIps),
            'webhook_url' => $webhookUrl,
            'webhook_secret' => $webhookSecret,
            'created_by' => $actor->id,
        ]);
        $client->save();

        Audit::record('integration', 'client_created', "Klien integrasi {$client->name} dibuat untuk {$vendor->code}", $actor, $client);

        return ['client' => $client, 'secret' => $secret, 'webhook_secret' => $webhookSecret];
    }
}
