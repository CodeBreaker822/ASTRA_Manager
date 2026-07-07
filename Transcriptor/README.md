# Transcriptor Integration Export

This folder contains the portable AI chatbot, transcription API, license-key API manager, provider settings, and transcriber package update pieces from this Laravel app.

## Main Folders

- `app/Http/Controllers/Api` - license manager, provider settings, transcription, polish, license status, and update download controllers.
- `app/Http/Controllers/AIBot` - chatbot controller and placeholder tool controller.
- `app/Models` - API license, provider setting, and request log models.
- `app/Services` - provider adapters, chatbot routing, fallback logging, request logging, and provider settings catalog.
- `app/Exceptions` - custom provider exceptions required by the services.
- `database/migrations` - schema for license keys, provider settings, and transcription API logs.
- `resources/views` - API Settings UI, provider list partial, add-license modal, and chat widget.
- `routes` - focused snippets for API routes, web/admin routes, and chatbot routes.
- `app/Gates` and `app/Traits` - gate registration and position-based permission checks for the API manager.
- `config/services.php` - provider endpoint/timeouts/model config used by these services.
- `public/js` - notification/loader helpers used by the copied API Settings page.
- `AI Documentations` - current integration and chatbot docs.
- `GATE_PROTOCOL.md` - how gates are registered, fetched by the user manager, assigned to positions, and checked at runtime.

## Install Notes

1. Copy the folders into the target Laravel project, merging with existing `app`, `database`, `resources`, `routes`, `config`, and `public` folders.
2. Merge the AI/transcription sections from `config/services.php` if the target project already has its own services config.
3. Add the routes:
   - Add the contents of `routes/transcription-api.php` to the target project's `routes/api.php`.
   - Add the contents of `routes/transcription-web.php` to the target project's `routes/web.php`.
   - Add the contents of `routes/user-permissions.php` if the target server should use this same position-based permission manager.
   - Require or copy `routes/chatbot.php` if the target project will use the chatbot widget.
4. Run migrations after copying the database files.
5. Make sure the target project has an `APP_KEY`; encrypted provider API keys depend on Laravel encryption.
6. Create or map the authorization gate `API-manage_api` for the admin API Settings routes, or adjust the middleware in `routes/transcription-web.php`.
7. Published transcriber ZIPs and `version.json` are stored under private local storage at `storage/app/private/transcriber` on recent Laravel versions.

For the same user-manager behavior as this app, register `APIManagerGates::register()` and `UserGates::register()` during app boot, then assign `API-manage_api` to a user position through the permission manager. See `GATE_PROTOCOL.md`.

## Public API Contract

Clients should use:

- `GET /api/license/status`
- `POST /api/transcribe`
- `POST /api/polish`
- `GET /transcriber/{zipfile}` using the exact path returned by license status
- Legacy download: `GET /api/transcribe/update/zipfile`

All client requests require:

```http
Authorization: Bearer LICENSE_KEY
```

The license status response exposes `version`, `zipfile`, and `apis.transcriber_update.allowed/path` for standalone update integration.
