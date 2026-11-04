<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile Token Lifetimes
    |--------------------------------------------------------------------------
    |
    | The access token is short-lived and sent on every API call. The refresh
    | token is long-lived, bound to a device, and rotated on every use.
    |
    */

    'access_token_ttl_minutes' => (int) env('IAM_ACCESS_TOKEN_TTL_MINUTES', 30),

    'refresh_token_ttl_days' => (int) env('IAM_REFRESH_TOKEN_TTL_DAYS', 30),

];
