<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reporting Time
    |--------------------------------------------------------------------------
    |
    | Days are cut at midnight in this timezone, so "today" on the dashboard and the date range of a report
    | mean the same thing to the people reading them, not midnight in UTC.
    |
    */

    'timezone' => env('REPORT_TIMEZONE', 'Asia/Jakarta'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    'trend_days' => 14,

    'performance_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    |
    | A hard ceiling on one export, so a report with no filters cannot tie up the server for minutes.
    |
    */

    'max_export_rows' => (int) env('REPORT_MAX_EXPORT_ROWS', 50000),

];
