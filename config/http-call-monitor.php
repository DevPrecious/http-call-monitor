<?php

return [

    /*
    | Turn recording on or off. It stays off under APP_ENV=testing so your test
    | suite's faked responses don't end up in the statistics.
    */
    'enabled' => env('HTTP_CALL_MONITOR_ENABLED', env('APP_ENV') !== 'testing'),

    /*
    | Where calls are stored. Leave the connection null to use your default one.
    */
    'storage' => [
        'connection' => env('HTTP_CALL_MONITOR_DB_CONNECTION'),
        'table' => 'http_calls',
    ],

    /*
    | How many calls to hold in memory before writing them. Anything left is
    | written when the request, command or queued job finishes.
    */
    'flush_at' => 50,

    /*
    | Calls older than this are deleted daily by the scheduler. Set to null to
    | keep everything and disable the scheduled cleanup.
    */
    'retention_days' => 14,

    /*
    | Hosts to leave out of monitoring. Wildcards are allowed: '*.internal'.
    */
    'ignore_hosts' => [],

    /*
    | Extra regex replacements applied to paths before they are stored, for
    | anything the built-in identifier masking misses.
    |
    | Example: '#/tenants/[^/]+#' => '/tenants/{tenant}'
    */
    'path_replacements' => [],

    /*
    | The web dashboard. Access is controlled by the "viewHttpCallMonitor" gate,
    | which only allows the local environment until you define your own.
    */
    'dashboard' => [
        'enabled' => env('HTTP_CALL_MONITOR_DASHBOARD', true),
        'path' => env('HTTP_CALL_MONITOR_PATH', 'http-call-monitor'),
        'middleware' => ['web'],
    ],

];
