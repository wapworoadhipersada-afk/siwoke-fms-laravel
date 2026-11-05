<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound Requests
    |--------------------------------------------------------------------------
    |
    | A signed request is only accepted within this many seconds of its timestamp, and each nonce only once
    | inside that window, so a captured request cannot be replayed later.
    |
    */

    'timestamp_tolerance_seconds' => (int) env('INTEGRATION_TIMESTAMP_TOLERANCE', 300),

    'requests_per_minute' => (int) env('INTEGRATION_RATE_PER_MINUTE', 120),

    /*
    |--------------------------------------------------------------------------
    | Outbound Callbacks
    |--------------------------------------------------------------------------
    |
    | Seconds to wait before each retry of a callback that failed. After the last one the event is marked
    | dead and shows up for a person to look at. Insecure webhooks (http, private addresses) exist only for
    | local development and must stay off elsewhere.
    |
    */

    'retry_backoff_seconds' => [60, 300, 1800, 7200, 43200],

    'webhook_timeout_seconds' => (int) env('INTEGRATION_WEBHOOK_TIMEOUT', 10),

    'allow_insecure_webhooks' => (bool) env('INTEGRATION_ALLOW_INSECURE_WEBHOOKS', false),

];
