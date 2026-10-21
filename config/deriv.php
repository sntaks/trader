<?php

return [
    /*
    | `app_id` is the OAuth app's client ID, sent with sign-in tokens. `app_id_pat` is the PAT app's ID,
    | sent as Deriv-App-ID with Personal Access Tokens (falls back to `app_id` when empty). Markup is
    | earned by whichever app a trade goes through, so both apps need it configured.
    */
    'app_id' => env('DERIV_APP_ID'),
    'app_id_pat' => env('DERIV_APP_ID_PAT'),
    'oauth_url' => 'https://oauth.deriv.com/oauth2/authorize',

    /*
    | A Personal Access Token from the Deriv account that owns both apps, with the `application_read`
    | scope. It reads markup statistics for the admin dashboard and is never shown or sent elsewhere.
    */
    'partner_token' => env('DERIV_PARTNER_TOKEN'),

    /*
    | Affiliate tagging, from the Partners dashboard. When `affiliate_token` is set, Deriv sign-in and
    | sign-up links carry `affiliate_token`, `utm_medium=affiliate`, `utm_campaign` and `utm_source`
    | (the affiliate ID), as Deriv's OAuth documentation names them.
    */
    'affiliate_token' => env('DERIV_AFFILIATE_TOKEN'),
    'affiliate_id' => env('DERIV_AFFILIATE_ID'),
    'utm_campaign' => env('DERIV_UTM_CAMPAIGN', 'copytrader'),

    /*
    | Scopes requested at Deriv sign-in. `trade` covers every call this app makes. Deriv does not
    | document refresh tokens; add `offline_access` only after a test login shows Deriv accepts it.
    */
    'oauth_scopes' => env('DERIV_OAUTH_SCOPES', 'trade'),
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
