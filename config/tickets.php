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

];
