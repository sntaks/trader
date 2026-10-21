<?php

return [
    'app_id' => env('DERIV_APP_ID'),
    'app_id_pat' => env('DERIV_APP_ID_PAT'),
    'legacy_app_id' => env('DERIV_LEGACY_APP_ID', '1089'),
    'oauth_url' => 'https://oauth.deriv.com/oauth2/authorize',
    'redirect_uri' => env('DERIV_REDIRECT_URI', ''),
    'max_stake' => (float) env('DERIV_MAX_STAKE', 1000),
];
