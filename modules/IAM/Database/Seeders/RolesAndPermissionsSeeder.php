<?php

declare(strict_types=1);

namespace Modules\IAM\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Permissions as P;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: safe to run on every deploy. It creates what is missing and re-syncs each built-in role to the
 * matrix below, unless an administrator has edited that role's permissions on the role screen.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (P::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach ($this->matrix() as $name => $permissions) {
            $role = Role::findOrCreate($name, 'web');
            $profile = RoleName::tryFrom($name);

            // Built-in roles get a readable name and are protected from deletion, whatever an administrator did before.
            $role->forceFill(['is_system' => true, 'label' => $role->getAttribute('label') ?? $profile?->label()])->save();

            // A role an administrator has edited is theirs now; a deploy must not put it back to the defaults.
            // Super Admin is the exception: it is always everything.
            if (! (bool) $role->getAttribute('is_customized') || $name === RoleName::SuperAdmin->value) {
                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, list<string>>
     */
    private function matrix(): array
    {
        $views = [
            P::MASTER_VENDOR_VIEW, P::MASTER_LOCATION_VIEW, P::MASTER_MERCHANT_VIEW, P::MASTER_TERMINAL_VIEW,
            P::ASSET_VIEW, P::TICKET_VIEW, P::DOCUMENT_VIEW, P::REPORT_VIEW,
        ];

        return [
            RoleName::SuperAdmin->value => P::all(),

            RoleName::Managerial->value => [
                ...$views,
                P::IAM_USER_VIEW, P::AUDIT_VIEW, P::INTEGRATION_LOG_VIEW, P::DATA_SCOPE_ALL,
                P::REPORT_EXPORT, P::DOCUMENT_BA_DOWNLOAD,
            ],

            RoleName::Leader->value => [
                ...$views,
                P::TICKET_CREATE, P::TICKET_UPDATE, P::TICKET_ASSIGN, P::TICKET_CANCEL, P::TICKET_REOPEN,
                P::ASSET_TRANSFER, P::ASSET_TRANSFER_APPROVE,
                P::DOCUMENT_BA_DOWNLOAD, P::DOCUMENT_BA_GENERATE, P::REPORT_EXPORT,
            ],

            RoleName::Coordinator->value => [
                ...$views,
                P::TICKET_CREATE, P::TICKET_UPDATE, P::TICKET_ASSIGN,
                P::ASSET_TRANSFER,
                P::DOCUMENT_BA_DOWNLOAD,
            ],

            RoleName::OperationalAdmin->value => [
                ...$views,
                P::IAM_USER_VIEW,
                P::MASTER_VENDOR_MANAGE, P::MASTER_LOCATION_MANAGE, P::MASTER_MERCHANT_MANAGE, P::MASTER_TERMINAL_MANAGE,
                P::TICKET_CREATE, P::TICKET_UPDATE, P::TICKET_ASSIGN, P::TICKET_CANCEL,
                P::ASSET_CREATE, P::ASSET_UPDATE, P::ASSET_TRANSFER, P::ASSET_CATALOG_MANAGE,
                P::DOCUMENT_BA_DOWNLOAD, P::DOCUMENT_BA_GENERATE,
            ],

            RoleName::Technician->value => [
                P::MOBILE_ACCESS,
                P::TICKET_VIEW, P::TICKET_WORK,
                P::ASSET_VIEW, P::ASSET_TRANSFER_RECEIVE,
                P::DOCUMENT_VIEW,
            ],
        ];
    }
}
