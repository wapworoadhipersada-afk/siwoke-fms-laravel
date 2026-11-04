<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\Asset\AssetServiceProvider;
use Modules\IAM\IAMServiceProvider;
use Modules\Master\MasterServiceProvider;
use Modules\Shared\SharedServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IAMServiceProvider::class,
    MasterServiceProvider::class,
    AssetServiceProvider::class,
    SharedServiceProvider::class,
];
