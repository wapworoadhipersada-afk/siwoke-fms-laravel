<?php

declare(strict_types=1);

namespace Modules\Integration\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Integration\Application\Actions\CreateIntegrationClient;
use Modules\Integration\Application\Actions\RotateClientSecret;
use Modules\Integration\Domain\Models\IntegrationClient;
use Modules\Integration\Http\Web\Requests\IntegrationClientRequest;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Http\ModuleController;
use Modules\Shared\Support\Audit;

/**
 * Where an administrator gives a vendor's system its credentials. Secrets are shown once, on the page that
 * answers the action, and are never put in the session or in a link.
 */
final class IntegrationClientController extends ModuleController
{
    public function index(): Response
    {
        $this->allow(Permissions::INTEGRATION_CLIENT_MANAGE);

        return Inertia::render('integration/clients/Index', [
            'clients' => IntegrationClient::query()->with('vendor')->orderBy('vendor_id')->orderBy('name')->get()->map(fn (IntegrationClient $client): array => [
                'ulid' => $client->ulid,
                'name' => $client->name,
                'vendor' => "{$client->vendor->code} — {$client->vendor->name}",
                'key_id' => $client->key_id,
                'is_active' => $client->is_active,
                'has_webhook' => $client->webhook_url !== null,
                'webhook_url' => $client->webhook_url,
                'allowed_ips' => $client->allowed_ips,
                'last_used_at' => $client->last_used_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::INTEGRATION_CLIENT_MANAGE);

        return Inertia::render('integration/clients/Create', [
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get()->map(fn (Vendor $vendor): array => [
                'ulid' => $vendor->ulid,
                'label' => "{$vendor->code} — {$vendor->name}",
            ])->values()->all(),
        ]);
    }

    public function store(IntegrationClientRequest $request, CreateIntegrationClient $action): Response
    {
        $vendor = Vendor::query()->where('ulid', (string) $request->validated('vendor'))->firstOrFail();

        $created = $this->translatingErrors([], fn (): array => $action->handle(
            $vendor,
            (string) $request->validated('name'),
            $request->addresses(),
            $request->validated('webhook_url'),
            $this->user(),
        ));

        return Inertia::render('integration/clients/Credentials', [
            'client' => ['name' => $created['client']->name, 'vendor' => $vendor->code, 'key_id' => $created['client']->key_id],
            'secret' => $created['secret'],
            'webhook_secret' => $created['webhook_secret'],
        ]);
    }

    public function rotate(IntegrationClient $client, RotateClientSecret $action): Response
    {
        $this->allow(Permissions::INTEGRATION_CLIENT_MANAGE);

        $rotated = $action->handle($client, $this->user());

        return Inertia::render('integration/clients/Credentials', [
            'client' => ['name' => $client->name, 'vendor' => $client->vendor->code, 'key_id' => $client->key_id],
            'secret' => $rotated['secret'],
            'webhook_secret' => $rotated['webhook_secret'],
        ]);
    }

    public function toggle(IntegrationClient $client): RedirectResponse
    {
        $this->allow(Permissions::INTEGRATION_CLIENT_MANAGE);

        $client->forceFill(['is_active' => ! $client->is_active])->save();
        Audit::record('integration', $client->is_active ? 'client_enabled' : 'client_disabled', "Klien {$client->name} ".($client->is_active ? 'diaktifkan' : 'dinonaktifkan'), $this->user(), $client);
        $this->success($client->is_active ? 'Klien diaktifkan.' : 'Klien dinonaktifkan.');

        return to_route('integration.clients.index');
    }
}
