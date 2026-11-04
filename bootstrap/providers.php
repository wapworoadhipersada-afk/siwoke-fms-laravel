<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\Asset\AssetServiceProvider;
use Modules\IAM\IAMServiceProvider;
use Modules\Master\MasterServiceProvider;
use Modules\Shared\SharedServiceProvider;
use Modules\Ticket\TicketServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IAMServiceProvider::class,
    MasterServiceProvider::class,
    AssetServiceProvider::class,
    SharedServiceProvider::class,
    TicketServiceProvider::class,
];
