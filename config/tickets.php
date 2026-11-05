<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Arrival Radius
    |--------------------------------------------------------------------------
    |
    | How far from the site's coordinates a technician may mark "arrived" before the arrival is
    | flagged as outside the radius. It is a flag for review, never a block: GPS is unreliable indoors.
    |
    */

    'arrival_radius_meters' => (int) env('TICKET_ARRIVAL_RADIUS_METERS', 300),

    /*
    |--------------------------------------------------------------------------
    | Offline Clock Tolerance
    |--------------------------------------------------------------------------
    |
    | A phone without signal sends its actions later, stamped with its own clock. We accept that,
    | within these bounds, so a wrong or tampered clock cannot rewrite history.
    |
    */

    'max_future_minutes' => (int) env('TICKET_MAX_FUTURE_MINUTES', 5),

    'max_past_days' => (int) env('TICKET_MAX_PAST_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Berita Acara
    |--------------------------------------------------------------------------
    |
    | A report is produced in the background whenever a visit ends. Switch auto_generate off to
    | produce them only on request (tests do this so closing a ticket stays fast).
    |
    */

    'berita_acara' => [
        'auto_generate' => (bool) env('BA_AUTO_GENERATE', true),
        'max_photos' => (int) env('BA_MAX_PHOTOS', 8),
        // Times printed on the report are shown in the timezone of the people who sign it.
        'timezone' => env('BA_TIMEZONE', 'Asia/Jakarta'),
    ],

];
