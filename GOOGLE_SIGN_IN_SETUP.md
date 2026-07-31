# Google Sign-In Setup for JERVA

JERVA uses Laravel Socialite's server-side OAuth flow. It requests only the
`openid` and `email` identity scopes. JERVA does not request the Google profile
scope and does not store a Google name, avatar, access token, or refresh token.

## 1. Decide which URLs you will configure

Use these values for local development:

- Application URL: `http://127.0.0.1:8000`
- OAuth callback: `http://127.0.0.1:8000/auth/google/callback`

Use these values for production:

- Application URL: `https://usejerva.com`
- OAuth callback: `https://usejerva.com/auth/google/callback`

Google requires the callback URL to match exactly. The scheme, hostname, port,
path, and trailing slash must be the same in Google Cloud and JERVA.

## 2. Create or select a Google Cloud project

1. Open the [Google Cloud Console](https://console.cloud.google.com/).
2. Use the project picker at the top of the page.
3. Create a project for JERVA, or select the project you want to use.
4. Google recommends separate projects for development/testing and production
   when practical.

## 3. Configure Google Auth Platform

1. Open [Google Auth Platform](https://console.cloud.google.com/auth/overview).
2. Click **Get started** if the project has not been configured.
3. Enter `JERVA Transcriber` as the app name.
4. Select an actively monitored user support email.
5. Choose the audience:
   - Choose **External** to allow any Google Account.
   - Choose **Internal** only if every user belongs to your Google Workspace
     organization.
6. Enter the developer contact email and save.

Google's current configuration guide explains the Branding, Audience, Clients,
Data Access, and Verification Center areas:
[Google Auth Platform setup](https://support.google.com/cloud/answer/15544987?hl=en).

## 4. Keep data access minimal

1. Open **Google Auth Platform → Data Access**.
2. Keep only the basic identity scopes needed for authentication:
   - `openid`
   - Google Account email
3. Do not add Google Drive, Gmail, Calendar, Contacts, or other API scopes.
4. JERVA intentionally does not request the `profile` scope because the
   application does not collect a user's name or Google profile photo.

Basic identity scopes are lower risk than sensitive or restricted Google API
scopes. Google's verification guidance is available at
[OAuth App Verification](https://support.google.com/cloud/answer/13463073?hl=en).

## 5. Add test users while the app is in Testing

1. Open **Google Auth Platform → Audience**.
2. Leave the publishing status as **Testing** while configuring the integration.
3. Add the Google accounts that should be allowed to test.
4. Save the changes.

Testing projects are limited to the listed test users, up to Google's current
test-user limit. Test authorizations can expire after seven days. See
[Manage App Audience](https://support.google.com/cloud/answer/15549945?hl=en).

## 6. Create the OAuth client and obtain the key

1. Open **Google Auth Platform → Clients**.
2. Click **Create client**.
3. Select **Web application**.
4. Use a recognizable name such as `JERVA Web Production`.
5. Under **Authorized JavaScript origins**, add:
   - Local: `http://127.0.0.1:8000`
   - Production: `https://usejerva.com`
6. Under **Authorized redirect URIs**, add:
   - Local: `http://127.0.0.1:8000/auth/google/callback`
   - Production: `https://usejerva.com/auth/google/callback`
7. Click **Create**.
8. Copy the generated **Client ID** and **Client secret**.

The client secret must stay on the server. Never place it in a `VITE_`
environment variable, browser code, source control, screenshots, or support
messages.

## 7. Configure JERVA locally

Add the following values to `TranscriptionServer/.env`:

```dotenv
APP_URL=http://127.0.0.1:8000

GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
```

Clear Laravel's cached configuration:

```bash
php artisan optimize:clear
```

Open `/login` or `/register`. The **Continue with Google** button appears only
when all three Google environment values are configured.

## 8. Configure production

Set these server-side environment values:

```dotenv
APP_URL=https://usejerva.com

GOOGLE_CLIENT_ID=your-production-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-production-client-secret
GOOGLE_REDIRECT_URI=https://usejerva.com/auth/google/callback
```

Then deploy and run:

```bash
php artisan migrate --force
php artisan optimize:clear
```

The migration removes the old `users.name` column. Back up the production
database before migrating if the historical names must be retained outside
JERVA.

## 9. Publish the Google app

1. Test both a new Google email and an email that already exists in JERVA.
2. Open **Google Auth Platform → Audience**.
3. Change the publishing status to **In production** when ready.
4. Complete any branding or verification steps Google displays.

An External app in Testing is available only to configured test users. An app
in Production can be available to Google users generally. Google may require
brand verification before the final app name or logo is displayed.

## Expected JERVA behavior

- If the verified Google email already exists, JERVA signs in to that account.
- If it does not exist, JERVA creates an account using only the email.
- A successfully returned Google email is marked verified immediately.
- Google users are not redirected to JERVA's email-verification screen.
- JERVA does not store the Google name, avatar, access token, or refresh token.
- Banned or deactivated JERVA accounts cannot sign in with Google.

## Troubleshooting

### `redirect_uri_mismatch`

Compare `GOOGLE_REDIRECT_URI` with the Google client's authorized redirect URI.
They must match character for character.

### Google button is missing

Confirm that `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and
`GOOGLE_REDIRECT_URI` are all set, then run:

```bash
php artisan optimize:clear
```

### Only selected accounts can sign in

The Google app is probably in Testing. Add the email under **Audience → Test
users**, or publish the app when production configuration is complete.

### OAuth state or session error

Confirm that the login and callback use the same hostname. Do not start at
`localhost` and return to `127.0.0.1`, or start at HTTP and return to HTTPS.
Also verify the production session cookie and proxy HTTPS settings.
