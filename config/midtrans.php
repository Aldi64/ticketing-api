<?php

/*
|--------------------------------------------------------------------------
| Midtrans
|--------------------------------------------------------------------------
|
| Keys come from the Midtrans dashboard: Settings > Access Keys. Make sure the
| Environment drop-down (top left) says "Sandbox" while developing, and keep
| MIDTRANS_IS_PRODUCTION=false until you really go live.
|
*/

$production = filter_var(env('MIDTRANS_IS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN);

return [

    'server_key' => env('MIDTRANS_SERVER_KEY'),

    'client_key' => env('MIDTRANS_CLIENT_KEY'),

    'is_production' => $production,

    'snap_url' => $production
        ? 'https://app.midtrans.com/snap/v1/transactions'
        : 'https://app.sandbox.midtrans.com/snap/v1/transactions',

    'api_url' => $production
        ? 'https://api.midtrans.com/v2'
        : 'https://api.sandbox.midtrans.com/v2',

    'snap_js_url' => $production
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js',

    'redirect_base_url' => $production
        ? 'https://app.midtrans.com/snap/v4/redirection'
        : 'https://app.sandbox.midtrans.com/snap/v4/redirection',

];
