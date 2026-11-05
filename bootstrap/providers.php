<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\Asset\AssetServiceProvider;
use Modules\Document\DocumentServiceProvider;
use Modules\IAM\IAMServiceProvider;
use Modules\Integration\IntegrationServiceProvider;
use Modules\Master\MasterServiceProvider;
use Modules\Report\ReportServiceProvider;
use Modules\Shared\SharedServiceProvider;
use Modules\Ticket\TicketServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IAMServiceProvider::class,
    MasterServiceProvider::class,
    AssetServiceProvider::class,
    DocumentServiceProvider::class,
    SharedServiceProvider::class,
    TicketServiceProvider::class,
    IntegrationServiceProvider::class,
    ReportServiceProvider::class,
];
