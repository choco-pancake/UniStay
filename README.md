# UniStay
Student Dorm Accommodation System

## Email and password accounts

For a new database, use `database/schema.sql`. For an existing database, run `database/email-password-migration.sql` once before registering accounts. Users can create tenant or landlord accounts at `auth/register.php`; passwords are stored as PHP password hashes.

## Google sign-in

Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in the environment available to PHP. In Google Cloud Console, configure a Web application OAuth client and allow the callback URLs for both dashboards, for example `http://localhost:8000/tenant/dashboard.php` and `http://localhost:8000/landlord/dashboard.php`. The application uses PHP cURL to exchange the authorization code and retrieve the verified Google profile.
