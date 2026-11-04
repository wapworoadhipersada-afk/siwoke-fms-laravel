<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\IAM\IAMServiceProvider;
use Modules\Master\MasterServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IAMServiceProvider::class,
    MasterServiceProvider::class,
];
