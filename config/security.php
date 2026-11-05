<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | "enforce" makes the browser refuse anything the policy does not allow (the setting for production),
    | "report-only" only notes violations in the browser console, so a change can be tried safely, and "off"
    | sends no policy. The policy is skipped while the Vite dev server is running (public/hot), because it
    | serves scripts from another address.
    |
    */

    'csp' => env('SECURITY_CSP', env('APP_ENV') === 'production' ? 'enforce' : 'off'),

    /*
    |--------------------------------------------------------------------------
    | Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Tells browsers to use https only for this long. Sent only on https requests. Raise it once https is
    | certain to stay: a browser that has seen it will refuse plain http until it expires.
    |
    */

    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', env('APP_ENV') === 'production' ? 15552000 : 0),

];
