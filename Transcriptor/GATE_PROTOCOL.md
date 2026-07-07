# Gate Protocol for API Manager Integration

This export uses Laravel gates as named permissions that are assigned to user positions. The API Settings page is protected by the gate named `API-manage_api`.

## Files Included

- `app/Gates/APIManagerGates.php` defines `API-manage_api`.
- `app/Gates/UserGates.php` defines the permission-manager gates, including `user.manage-permissions`.
- `app/Traits/HasGatePermissions.php` checks whether the authenticated user's `position_id` has a matching row in `user_permissions`.
- `app/Traits/Gates.php` reads all registered gates through `Gate::abilities()` and groups them for the user manager UI.
- `app/Http/Controllers/Auth/UserAuthController.php` contains the active permission-manager methods.
- `app/Models/UserPositions.php` and `app/Models/UserPermissions.php` store positions and assigned gates.
- `resources/views/settings/permission.blade.php` and the permission modals render the user manager UI.
- `routes/user-permissions.php` contains the focused route snippet.

## Registration Flow

Register the gates during application boot. This export includes `app/Providers/TranscriptorGateServiceProvider.php` as a focused example:

```php
use App\Gates\APIManagerGates;
use App\Gates\UserGates;

public function boot(): void
{
    UserGates::register();
    APIManagerGates::register();
}
```

`APIManagerGates::register()` calls:

```php
Gate::define('API-manage_api', function (User $user): bool {
    return self::checkPermission($user, 'API-manage_api');
});
```

The admin API Settings routes then use:

```php
Route::middleware('can:API-manage_api')->group(function () {
    // API manager routes
});
```

## How Gates Are Read

The permission manager page calls:

```php
$gates = $this->getAllGates();
```

That method is provided by `app/Traits/Gates.php`. It reads every registered Laravel ability:

```php
$gates = Gate::abilities();
```

It then groups the gate names into categories and labels for the checkbox UI. Example:

- Gate name: `API-manage_api`
- Category shown: `API`
- Stored permission value: `API-manage_api`

Because the UI reads registered abilities, the gate must be registered before the permissions page is rendered.

## How Gates Are Assigned

The current user manager stores permissions by position, not directly by user.

When creating a position:

1. `UserAuthController::storePermission()` validates `position` and `permissions[]`.
2. It creates a row in `user_positions`.
3. It creates one `user_permissions` row per selected gate:

```php
UserPermissions::create([
    'position_id' => $position->id,
    'permission_name' => $permission,
]);
```

When editing a position:

1. `UserAuthController::updatePermission()` finds the position.
2. It deletes existing `user_permissions` rows for that position.
3. It recreates the selected permission rows.

When fetching assigned permissions:

```php
$positions = UserPositions::with('permissions')->get();
```

The JSON response returns each position with:

```json
{
  "id": 1,
  "position_name": "Administrator",
  "permissions": ["API-manage_api", "user.manage-permissions"]
}
```

## How Gates Are Checked

`HasGatePermissions::checkPermission()` grants access when:

- The user has `user_status === 'admin'`, or
- The user's `position_id` has a `user_permissions.permission_name` row matching the requested gate.

For the API manager, the required row is:

```text
position_id: <user position id>
permission_name: API-manage_api
```

The target server's `users` table/model must expose:

- `position_id`
- `user_status`

If the target app uses a different user-role structure, keep the gate name but adapt `HasGatePermissions::checkPermission()`.
