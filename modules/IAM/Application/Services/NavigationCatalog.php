<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use Modules\IAM\Domain\Permissions;

/**
 * Every page that can appear in the sidebar. Which pages exist, where they lead and what they need is decided
 * here, in code; an administrator decides how they are shown (name, icon, order, grouping, who sees them).
 * That split is what keeps the menu editor from ever pointing at a page nobody is allowed to open.
 */
final class NavigationCatalog
{
    /**
     * Groups have no route. `parent` is the key of the group an entry sits in.
     *
     * @return list<array{key: string, parent: string|null, section: string, label: string, route: string|null, icon: string, permission: string|null}>
     */
    public static function entries(): array
    {
        $group = static fn (string $key, string $section, string $label, string $icon): array => ['key' => $key, 'parent' => null, 'section' => $section, 'label' => $label, 'route' => null, 'icon' => $icon, 'permission' => null];
        $page = static fn (string $key, ?string $parent, string $section, string $label, string $route, string $icon, ?string $permission): array => ['key' => $key, 'parent' => $parent, 'section' => $section, 'label' => $label, 'route' => $route, 'icon' => $icon, 'permission' => $permission];

        return [
            $page('dashboard', null, 'Menu', 'Dashboard', 'dashboard', 'grid', null),
            $page('profile.me', null, 'Menu', 'Profil Saya', 'me.show', 'user', null),

            $group('operations', 'Menu', 'Operasional', 'list'),
            $page('tickets', 'operations', 'Menu', 'Tiket', 'tickets.index', 'list', Permissions::TICKET_VIEW),
            $page('reports.tickets', 'operations', 'Menu', 'Laporan tiket', 'reports.tickets', 'list', Permissions::REPORT_VIEW),

            $group('master', 'Menu', 'Master Data', 'table'),
            $page('master.vendors', 'master', 'Menu', 'Vendor', 'master.vendors.index', 'table', Permissions::MASTER_VENDOR_VIEW),
            $page('master.merchants', 'master', 'Menu', 'Merchant', 'master.merchants.index', 'table', Permissions::MASTER_MERCHANT_VIEW),
            $page('master.terminals', 'master', 'Menu', 'TID', 'master.terminals.index', 'table', Permissions::MASTER_TERMINAL_VIEW),
            $page('master.documents', 'master', 'Menu', 'Dokumen', 'master.documents.index', 'file', Permissions::MASTER_TERMINAL_VIEW),

            $group('locations', 'Menu', 'Lokasi', 'map'),
            $page('locations.provinces', 'locations', 'Menu', 'Provinsi', 'master.provinces.index', 'map', Permissions::MASTER_LOCATION_VIEW),
            $page('locations.service_points', 'locations', 'Menu', 'Service Point', 'master.service-points.index', 'map', Permissions::MASTER_LOCATION_VIEW),
            $page('locations.areas', 'locations', 'Menu', 'Area Kelolaan', 'master.areas.index', 'map', Permissions::MASTER_LOCATION_VIEW),

            $group('assets', 'Menu', 'Aset', 'box'),
            $page('assets.list', 'assets', 'Menu', 'Aset', 'asset.assets.index', 'box', Permissions::ASSET_VIEW),
            $page('assets.stock', 'assets', 'Menu', 'Stok', 'asset.stock.index', 'box', Permissions::ASSET_VIEW),
            $page('assets.handovers', 'assets', 'Menu', 'Serah-terima', 'asset.handovers.index', 'box', Permissions::ASSET_VIEW),
            $page('assets.warehouses', 'assets', 'Menu', 'Gudang', 'asset.warehouses.index', 'box', Permissions::ASSET_VIEW),
            $page('assets.models', 'assets', 'Menu', 'Jenis aset', 'asset.models.index', 'box', Permissions::ASSET_VIEW),

            $group('access', 'Pengaturan', 'Pengguna dan akses', 'users'),
            $page('access.users', 'access', 'Pengaturan', 'Pengguna', 'iam.users.index', 'users', Permissions::IAM_USER_VIEW),
            $page('access.engineers', 'access', 'Pengaturan', 'Engineer / Teknisi', 'iam.engineers.index', 'user', Permissions::IAM_USER_VIEW),
            $page('access.team', 'access', 'Pengaturan', 'Tim', 'master.team.index', 'users', Permissions::IAM_USER_VIEW),

            $page('settings', null, 'Pengaturan', 'Pengaturan', 'iam.settings.index', 'gear', Permissions::IAM_ROLE_MANAGE),

            $group('integration', 'Integrasi', 'Vendor API', 'plug'),
            $page('integration.clients', 'integration', 'Integrasi', 'Klien integrasi', 'integration.clients.index', 'plug', Permissions::INTEGRATION_CLIENT_MANAGE),
            $page('integration.logs', 'integration', 'Integrasi', 'Log integrasi', 'integration.logs.index', 'plug', Permissions::INTEGRATION_LOG_VIEW),
        ];
    }

    /**
     * Icons an administrator may choose from: key => label. The keys are mapped to components in the sidebar.
     *
     * @return array<string, string>
     */
    public static function icons(): array
    {
        return [
            'grid' => 'Kotak (dashboard)',
            'list' => 'Daftar',
            'table' => 'Tabel',
            'box' => 'Kotak barang',
            'users' => 'Pengguna',
            'user' => 'Satu pengguna',
            'plug' => 'Konektor',
            'gear' => 'Roda gigi',
            'chart' => 'Grafik',
            'file' => 'Dokumen',
            'map' => 'Peta',
            'calendar' => 'Kalender',
            'task' => 'Tugas',
            'bell' => 'Lonceng',
            'mail' => 'Surat',
            'page' => 'Halaman',
        ];
    }
}
