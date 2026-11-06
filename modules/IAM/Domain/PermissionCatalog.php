<?php

declare(strict_types=1);

namespace Modules\IAM\Domain;

/**
 * How permissions are shown to people: grouped by area, in plain Indonesian. The permission names themselves
 * (see Permissions) stay the single list of what exists; this only decorates them for the role screen.
 */
final class PermissionCatalog
{
    /**
     * Permissions that let someone change who may do what. They are handed out by Super Admin only, because
     * anyone holding them could otherwise give themselves everything.
     *
     * @return list<string>
     */
    public static function privileged(): array
    {
        return [
            Permissions::IAM_ROLE_MANAGE,
            Permissions::IAM_USER_CREATE,
            Permissions::IAM_USER_UPDATE,
            Permissions::IAM_USER_DELETE,
            Permissions::DATA_SCOPE_ALL,
            Permissions::INTEGRATION_CLIENT_MANAGE,
        ];
    }

    /**
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string, privileged: bool}>}>
     */
    public static function groups(): array
    {
        $labels = self::labels();
        $groups = [];

        foreach (self::groupLabels() as $key => $groupLabel) {
            $members = [];

            foreach (Permissions::all() as $name) {
                if (self::groupOf($name) === $key) {
                    $members[] = ['name' => $name, 'label' => $labels[$name] ?? $name, 'privileged' => in_array($name, self::privileged(), true)];
                }
            }

            if ($members !== []) {
                $groups[] = ['key' => $key, 'label' => $groupLabel, 'permissions' => $members];
            }
        }

        return $groups;
    }

    private static function groupOf(string $permission): string
    {
        return match (true) {
            str_starts_with($permission, 'iam.') || in_array($permission, [Permissions::AUDIT_VIEW, Permissions::DATA_SCOPE_ALL], true) => 'iam',
            str_starts_with($permission, 'master.') => 'master',
            str_starts_with($permission, 'asset.') => 'asset',
            str_starts_with($permission, 'ticket.') => 'ticket',
            str_starts_with($permission, 'document.') => 'document',
            str_starts_with($permission, 'integration.') => 'integration',
            str_starts_with($permission, 'report.') => 'report',
            default => 'other',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function groupLabels(): array
    {
        return [
            'iam' => 'Pengguna dan akses',
            'master' => 'Data master',
            'asset' => 'Aset',
            'ticket' => 'Tiket',
            'document' => 'Dokumen',
            'integration' => 'Integrasi vendor',
            'report' => 'Laporan',
            'other' => 'Lainnya',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            Permissions::MOBILE_ACCESS => 'Masuk lewat aplikasi mobile',
            Permissions::AUDIT_VIEW => 'Melihat jejak audit',
            Permissions::DATA_SCOPE_ALL => 'Melihat data semua area (tanpa batas area kerja)',
            Permissions::IAM_USER_VIEW => 'Melihat daftar pengguna',
            Permissions::IAM_USER_CREATE => 'Menambah pengguna',
            Permissions::IAM_USER_UPDATE => 'Mengubah pengguna dan perannya',
            Permissions::IAM_USER_DELETE => 'Menghapus pengguna',
            Permissions::IAM_ROLE_MANAGE => 'Mengelola peran dan menu',
            Permissions::IAM_DEVICE_REVOKE => 'Mencabut perangkat teknisi',
            Permissions::MASTER_VENDOR_VIEW => 'Melihat vendor',
            Permissions::MASTER_VENDOR_MANAGE => 'Mengelola vendor',
            Permissions::MASTER_LOCATION_VIEW => 'Melihat lokasi',
            Permissions::MASTER_LOCATION_MANAGE => 'Mengelola lokasi',
            Permissions::MASTER_MERCHANT_VIEW => 'Melihat merchant',
            Permissions::MASTER_MERCHANT_MANAGE => 'Mengelola merchant',
            Permissions::MASTER_TERMINAL_VIEW => 'Melihat TID',
            Permissions::MASTER_TERMINAL_MANAGE => 'Mengelola TID',
            Permissions::ASSET_VIEW => 'Melihat aset dan stok',
            Permissions::ASSET_CREATE => 'Menambah aset',
            Permissions::ASSET_UPDATE => 'Mengubah aset',
            Permissions::ASSET_TRANSFER => 'Memindahkan aset',
            Permissions::ASSET_TRANSFER_APPROVE => 'Menyetujui perpindahan aset',
            Permissions::ASSET_TRANSFER_RECEIVE => 'Menerima aset (teknisi)',
            Permissions::ASSET_STOCK_ADJUST => 'Menyesuaikan stok',
            Permissions::ASSET_CATALOG_MANAGE => 'Mengelola jenis dan kategori aset',
            Permissions::TICKET_VIEW => 'Melihat tiket',
            Permissions::TICKET_CREATE => 'Membuat tiket',
            Permissions::TICKET_UPDATE => 'Mengubah tiket',
            Permissions::TICKET_ASSIGN => 'Menugaskan teknisi',
            Permissions::TICKET_CANCEL => 'Membatalkan tiket',
            Permissions::TICKET_REOPEN => 'Membuka kembali tiket',
            Permissions::TICKET_CLOSE_OVERRIDE => 'Menutup tiket atas nama teknisi',
            Permissions::TICKET_WORK => 'Mengerjakan tiket (teknisi)',
            Permissions::DOCUMENT_VIEW => 'Melihat dokumen dan bukti',
            Permissions::DOCUMENT_BA_DOWNLOAD => 'Mengunduh Berita Acara',
            Permissions::DOCUMENT_BA_GENERATE => 'Membuat ulang Berita Acara',
            Permissions::INTEGRATION_LOG_VIEW => 'Melihat log integrasi',
            Permissions::INTEGRATION_CLIENT_MANAGE => 'Mengelola klien integrasi',
            Permissions::REPORT_VIEW => 'Melihat laporan',
            Permissions::REPORT_EXPORT => 'Mengekspor laporan',
        ];
    }
}
