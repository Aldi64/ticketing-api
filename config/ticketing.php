<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the Next.js app. Used for links inside emails and notifications.
    | Set FRONTEND_URL in .env (no trailing slash).
    |
    */

    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),

];
