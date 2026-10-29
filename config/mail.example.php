<?php

/**
 * Copy this file to config/mail.local.php to set up the mail server.
 *
 * Gmail (https://myaccount.google.com/apppasswords): turn on 2-Step
 * Verification, create an app password for the account and use it as the
 * password below. Google asks for the normal account password to confirm the
 * app password.
 *
 *   host        smtp.gmail.com
 *   port        587
 *   encryption  tls (STARTTLS) or ssl (implicit TLS on port 465)
 *   username    the full address, for example unistay.app@gmail.com
 *   password    the app password, not the account password
 *   from        the address the mail is sent from, usually the same as the
 *               username
 */

return [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'YOUR_GMAIL_ADDRESS',
    'password' => 'YOUR_GOOGLE_APP_PASSWORD',
    'from' => 'YOUR_GMAIL_ADDRESS',
    'from_name' => 'UniStay',
    'admin_recipient' => 'unistaysads@gmail.com',
];
