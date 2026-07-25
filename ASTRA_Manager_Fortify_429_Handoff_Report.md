# Handoff Report: Fixing Laravel Fortify 429 Too Many Requests

## Issue Summary

After Laravel Fortify was added, the application began returning:

```text
429 Too Many Requests
```

The issue appears when accessing:

```text
/email/verify
```

Increasing the login limiter to 60 requests per minute did not solve the problem because the failing request is not being blocked by the named `login` limiter.

---

## Root Cause

The problem is in:

```text
config/fortify.php
```

The current configuration contains:

```php
'middleware' => ['web', 'throttle:3,1'],
```

This applies a shared limit of three requests per minute to every Fortify route, including:

- `/login`
- `/register`
- `/logout`
- `/email/verify`
- `/email/verification-notification`
- `/forgot-password`
- `/reset-password`
- two-factor authentication routes
- passkey routes

A normal authentication flow can already exceed the limit:

```text
GET /register
POST /register
GET /email/verify
GET /email/verify
```

The fourth Fortify request is rejected with HTTP 429.

The named login limiter inside `FortifyServiceProvider` only applies to the login submission route. Increasing it to 60 requests per minute does not change the global `throttle:3,1` middleware or the email verification limiter.

---

## Required Fix

### 1. Remove the global Fortify throttle

Open:

```text
config/fortify.php
```

Replace:

```php
'middleware' => ['web', 'throttle:3,1'],
```

With:

```php
'middleware' => ['web'],
```

Fortify should use separate rate limits for sensitive actions instead of applying a single throttle to every authentication route.

---

### 2. Connect the email verification limiter

In the same file, locate:

```php
'limiters' => [
    'login' => 'login',
    'two-factor' => 'two-factor',
    'passkeys' => 'passkeys',
],
```

Replace it with:

```php
'limiters' => [
    'login' => 'login',
    'two-factor' => 'two-factor',
    'verification' => 'email.verify',
    'passkeys' => 'passkeys',
],
```

The important addition is:

```php
'verification' => 'email.verify',
```

Fortify expects the configuration key to be named `verification`.

---

### 3. Restore a safe web login limiter

Open:

```text
app/Providers/FortifyServiceProvider.php
```

Replace the current login limiter with:

```php
RateLimiter::for('login', function (Request $request) {
    $email = Str::transliterate(
        Str::lower((string) $request->input(Fortify::username()))
    );

    return [
        Limit::perMinute(5)->by($email.'|'.$request->ip()),
        Limit::perHour(50)->by($request->ip()),
    ];
});
```

This allows five login attempts per email and IP combination while also preventing an attacker from cycling through many email addresses from one IP.

---

### 4. Configure email verification throttling

Use:

```php
RateLimiter::for('email.verify', function (Request $request) {
    $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

    return Limit::perMinute(6)->by((string) $identifier);
});
```

This safely identifies the authenticated user and falls back to the IP address when needed.

---

### 5. Separate desktop API login throttling

The desktop application login is separate from Fortify and uses Sanctum.

Open:

```text
routes/transcription-api.php
```

Replace:

```php
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:60,1');
```

With:

```php
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:desktop-login');
```

Then add this limiter inside `configureRateLimiting()` in:

```text
app/Providers/FortifyServiceProvider.php
```

```php
RateLimiter::for('desktop-login', function (Request $request) {
    $email = Str::transliterate(
        Str::lower((string) $request->input('email'))
    );

    return [
        Limit::perMinute(5)->by($email.'|'.$request->ip()),
        Limit::perHour(50)->by($request->ip()),
    ];
});
```

---

## Recommended Final `configureRateLimiting()` Method

```php
private function configureRateLimiting(): void
{
    RateLimiter::for('two-factor', function (Request $request) {
        return Limit::perMinute(5)->by(
            (string) ($request->session()->get('login.id') ?? $request->ip())
        );
    });

    RateLimiter::for('login', function (Request $request) {
        $email = Str::transliterate(
            Str::lower((string) $request->input(Fortify::username()))
        );

        return [
            Limit::perMinute(5)->by($email.'|'.$request->ip()),
            Limit::perHour(50)->by($request->ip()),
        ];
    });

    RateLimiter::for('desktop-login', function (Request $request) {
        $email = Str::transliterate(
            Str::lower((string) $request->input('email'))
        );

        return [
            Limit::perMinute(5)->by($email.'|'.$request->ip()),
            Limit::perHour(50)->by($request->ip()),
        ];
    });

    RateLimiter::for('password.reset', function (Request $request) {
        return Limit::perMinute(5)->by(
            Str::lower((string) ($request->input('email') ?? $request->ip()))
        );
    });

    RateLimiter::for('email.verify', function (Request $request) {
        $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

        return Limit::perMinute(6)->by((string) $identifier);
    });

    RateLimiter::for('passkeys', function (Request $request) {
        return Limit::perMinute(10)->by(
            ($request->input('credential.id') ?: $request->session()->getId())
            .'|'.$request->ip()
        );
    });
}
```

---

## Clear Existing Cached Rate Limits

The application uses the database cache driver, so existing throttle counters may remain active after the code is changed.

Run:

```bash
php artisan optimize:clear
php artisan cache:clear
```

Restart the local Laravel server:

```bash
php artisan serve
```

---

## Verify the Applied Middleware

Run:

```bash
php artisan route:list --path=email -vv
```

Check that `/email/verify` no longer has the global:

```text
throttle:3,1
```

The route may still have a dedicated verification limiter, which is expected.

---

## Expected Result

After applying the changes:

- `/email/verify` should load normally.
- Refreshing the verification page should not immediately cause HTTP 429.
- Login remains protected with a reasonable rate limit.
- Email verification requests remain protected separately.
- Desktop API login has its own limiter.
- Fortify routes are no longer globally restricted to three requests per minute.

---

## Primary Cause in One Line

```php
'middleware' => ['web', 'throttle:3,1'],
```

must be changed to:

```php
'middleware' => ['web'],
```
