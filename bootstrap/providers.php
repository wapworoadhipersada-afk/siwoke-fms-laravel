<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\IAM\IAMServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    IAMServiceProvider::class,
];
