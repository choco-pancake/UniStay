# UniStay
Student Dorm Accommodation System

## Email and password accounts

For a new database, use `database/schema.sql`. For an existing database, run `database/restructure-users-safe-migration.sql` and `database/password-reset-migration.sql` once before registering accounts. Users can create tenant or landlord accounts at `auth/register.php`; passwords are stored as PHP password hashes.

Tenants are sent to `tenant/complete-profile.php` to finish onboarding before reaching their dashboard. Landlords go straight to `landlord/dashboard.php`.

## Password reset

`auth/login.php` handles the whole flow: `login.php?view=forgot` asks for the email address and mails a one-hour reset link, `login.php?view=reset&token=...` sets the new password. Tokens are stored hashed in the `password_resets` table.

Mails are sent with PHPMailer over SMTP, so the mail server has to be set up first:

1. Run `composer install` in the project folder to fetch PHPMailer.
2. Open `http://localhost/unistay/tools/mail-settings.php` on this computer, paste the sending address and the app password, and save. The page writes `config/mail.local.php`, which is listed in `.gitignore`, so the credentials stay out of the repository. The same can be done by copying `config/mail.example.php` to `config/mail.local.php` and filling in the file, and the values can also be supplied with the `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM` and `SMTP_FROM_NAME` environment variables.
3. For Gmail, turn on 2-Step Verification, create an app password at <https://myaccount.google.com/apppasswords> and use it as the password: host `smtp.gmail.com`, port `587`, encryption `tls`. The spaces in the app password are removed automatically.
4. Check the setup with the test button on the mail settings page.

When no mail server is configured, or when the server refuses the message, the reset link is shown on the page and written to the PHP error log instead.

## Sign up with Google

The "Sign in with Google" button on `auth/login.php` uses the OAuth 2.0 authorization code flow and is handled by `auth/google_oauth.php`.

1. Create an OAuth client of type *Web application* in the Google Cloud console and add the callback of the app as an authorised redirect URI: `http://localhost/unistay/auth/google_oauth.php`
2. Copy `config/oauth.example.php` to `config/oauth.local.php` and fill in the client id and the client secret. That file is listed in `.gitignore`, so the credentials stay out of the repository. The same values can be supplied with the `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI` environment variables.
3. New accounts sign in as a tenant and are sent to `tenant/complete-profile.php`. Existing accounts sign in with their stored role.

## Top bar and page transitions

`assets/css/topbar.css` holds the top bar that the landing page and the auth pages share, so the header looks the same on every page. `assets/css/page-transition.css` and `assets/js/page-transition.js` cross-fade the landing page and the login page, in both directions and when the browser back button is used.
