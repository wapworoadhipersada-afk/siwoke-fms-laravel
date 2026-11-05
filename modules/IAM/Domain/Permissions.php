<?php

declare(strict_types=1);

namespace Modules\IAM\Domain;

use ReflectionClass;

/**
 * Catalog of every permission in the system, named `module.resource.action`.
 * Check permissions in code through these constants, never through raw strings.
 */
final class Permissions
{
    public const MOBILE_ACCESS = 'mobile.access';

    public const AUDIT_VIEW = 'audit.view';

    /** Lifts the location-scope restriction: the holder sees data from every area. */
    public const DATA_SCOPE_ALL = 'data.scope.all';

    public const IAM_USER_VIEW = 'iam.user.view';

    public const IAM_USER_CREATE = 'iam.user.create';

    public const IAM_USER_UPDATE = 'iam.user.update';

    public const IAM_USER_DELETE = 'iam.user.delete';

    public const IAM_ROLE_MANAGE = 'iam.role.manage';

    public const IAM_DEVICE_REVOKE = 'iam.device.revoke';

    public const MASTER_VENDOR_VIEW = 'master.vendor.view';

    public const MASTER_VENDOR_MANAGE = 'master.vendor.manage';

    public const MASTER_LOCATION_VIEW = 'master.location.view';

    public const MASTER_LOCATION_MANAGE = 'master.location.manage';

    public const MASTER_MERCHANT_VIEW = 'master.merchant.view';

    public const MASTER_MERCHANT_MANAGE = 'master.merchant.manage';

    public const MASTER_TERMINAL_VIEW = 'master.terminal.view';

    public const MASTER_TERMINAL_MANAGE = 'master.terminal.manage';

    public const ASSET_VIEW = 'asset.view';

    public const ASSET_CREATE = 'asset.create';

    public const ASSET_UPDATE = 'asset.update';

    public const ASSET_TRANSFER = 'asset.transfer';

    public const ASSET_TRANSFER_APPROVE = 'asset.transfer.approve';

    public const ASSET_TRANSFER_RECEIVE = 'asset.transfer.receive';

    public const ASSET_STOCK_ADJUST = 'asset.stock.adjust';

    public const ASSET_CATALOG_MANAGE = 'asset.catalog.manage';

    public const TICKET_VIEW = 'ticket.view';

    public const TICKET_CREATE = 'ticket.create';

    public const TICKET_UPDATE = 'ticket.update';

    public const TICKET_ASSIGN = 'ticket.assign';

    public const TICKET_CANCEL = 'ticket.cancel';

    public const TICKET_REOPEN = 'ticket.reopen';

    public const TICKET_CLOSE_OVERRIDE = 'ticket.close.override';

    public const TICKET_WORK = 'ticket.work';

    public const DOCUMENT_VIEW = 'document.view';

    public const DOCUMENT_BA_DOWNLOAD = 'document.ba.download';

    public const DOCUMENT_BA_GENERATE = 'document.ba.generate';

    public const INTEGRATION_LOG_VIEW = 'integration.log.view';

    public const INTEGRATION_CLIENT_MANAGE = 'integration.client.manage';

    public const REPORT_VIEW = 'report.view';

    public const REPORT_EXPORT = 'report.export';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $values = array_values((new ReflectionClass(self::class))->getConstants());

        return array_map(static fn (mixed $value): string => (string) $value, $values);
    }
}
