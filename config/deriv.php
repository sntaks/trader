<?php

return [
    'app_id' => env('DERIV_APP_ID'),
    'app_id_pat' => env('DERIV_APP_ID_PAT'),
    'legacy_app_id' => env('DERIV_LEGACY_APP_ID', '1089'),
    'oauth_url' => 'https://oauth.deriv.com/oauth2/authorize',
    'redirect_uri' => env('DERIV_REDIRECT_URI', ''),
    'max_stake' => (float) env('DERIV_MAX_STAKE', 1000),

    /*
    | Listener workers supervisor runs (numprocs of trader-queue-listener). Every running master and
    | every own-account user holds one for as long as its bot runs; ensure-listeners logs an error
    | when more connections need a listener than this.
    */
    'listener_workers' => (int) env('DERIV_LISTENER_WORKERS', 15),

    /*
    | Deriv's published REST limits and how this app shares them. Trade calls (buys, the master
    | listener) may use `trade_share` of each limit; reads (pages, settlement) only `read_share`.
    */
    'rate_limits' => [
        'ip_per_minute' => (int) env('DERIV_RATE_IP_PER_MINUTE', 300),
        'ip_per_ten_minutes' => (int) env('DERIV_RATE_IP_PER_TEN_MINUTES', 1000),
        'connection_per_minute' => (int) env('DERIV_RATE_CONNECTION_PER_MINUTE', 80),
        'trade_share' => (float) env('DERIV_RATE_TRADE_SHARE', 0.95),
        'read_share' => (float) env('DERIV_RATE_READ_SHARE', 0.6),
        'trade_wait_seconds' => (int) env('DERIV_RATE_TRADE_WAIT_SECONDS', 5),
        'default_cooldown_seconds' => (int) env('DERIV_RATE_DEFAULT_COOLDOWN_SECONDS', 30),
        'max_cooldown_seconds' => (int) env('DERIV_RATE_MAX_COOLDOWN_SECONDS', 120),
    ],
];
