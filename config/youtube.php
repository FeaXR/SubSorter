<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google OAuth client
    |--------------------------------------------------------------------------
    |
    | Create a "Web application" OAuth client in the Google Cloud console and
    | register the redirect URI below as an "Authorized redirect URI".
    |
    */

    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost:8080'), '/').'/auth/google/callback'),

    // Target playlists are chosen per channel in the UI (Channels page).

    // Shorts setting for newly found channels (changeable per channel on the Channels page).
    // false = "UULF" (long-form only) uploads playlist, true = "UU" (everything, incl. Shorts)
    'include_shorts' => (bool) env('INCLUDE_SHORTS', false),

    // How many upload playlists to request in parallel
    'concurrency' => (int) env('YOUTUBE_CONCURRENCY', 20),

    'display_timezone' => env('DISPLAY_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Automatic sync
    |--------------------------------------------------------------------------
    |
    | Cron expression for the scheduler container. When set, the scheduler
    | refreshes subscriptions and adds new videos without manual review.
    | Leave empty to only sync manually from the web UI.
    |
    */

    'auto_sync_cron' => env('AUTO_SYNC_CRON'),

    /*
    |--------------------------------------------------------------------------
    | Basic auth
    |--------------------------------------------------------------------------
    |
    | Anyone who can open the web UI can modify your YouTube playlists. Set
    | these to protect the UI with HTTP basic auth.
    |
    */

    'basic_auth' => [
        'user' => env('BASIC_AUTH_USER'),
        'password' => env('BASIC_AUTH_PASSWORD'),
    ],

];
