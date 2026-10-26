<?php

/**
 * Copy this file to config/oauth.local.php and fill in the credentials of
 * your OAuth client (Google Cloud console -> APIs & Services -> Credentials).
 *
 * The authorised redirect URI of the client must be the callback of the app:
 *   http://localhost/unistay/auth/google_oauth.php
 * (adjust the host when the project is not served from localhost).
 */

return [
    'client_id' => 'YOUR_GOOGLE_CLIENT_ID',
    'client_secret' => 'YOUR_GOOGLE_CLIENT_SECRET',
    'redirect_uri' => 'http://localhost/unistay/auth/google_oauth.php',
];
