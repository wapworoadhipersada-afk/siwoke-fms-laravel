<?php

declare(strict_types=1);

namespace Modules\Integration\Application\Actions;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Integration\Domain\Models\IntegrationClient;
use Modules\Shared\Support\Audit;

/**
 * Replaces a client's secrets at once; the old ones stop working immediately. Returned once, like at creation.
 */
final class RotateClientSecret
{
    /**
     * @return array{secret: string, webhook_secret: string|null}
     */
    public function handle(IntegrationClient $client, User $actor): array
    {
        $secret = Str::random(48);
        $webhookSecret = $client->webhook_url === null ? null : Str::random(48);

        $client->forceFill(['secret' => $secret, 'webhook_secret' => $webhookSecret])->save();

        Audit::record('integration', 'secret_rotated', "Secret klien {$client->name} diganti", $actor, $client);

        return ['secret' => $secret, 'webhook_secret' => $webhookSecret];
    }
}
