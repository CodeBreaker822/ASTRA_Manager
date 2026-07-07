<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use App\Traits\Gates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserManagerController extends Controller
{
    use Gates;

    public function index(): Response
    {
        Gate::authorize('user.manage-users');

        return Inertia::render('settings/Users', [
            'users' => User::query()
                ->with('position:id,position_name')
                ->select(['id', 'name', 'email', 'email_verified_at', 'position_id', 'user_status', 'created_at', 'updated_at'])
                ->latest()
                ->get(),
            'positions' => UserPositions::query()
                ->with('permissions:id,position_id,permission_name')
                ->orderBy('position_name')
                ->get()
                ->map(fn (UserPositions $position): array => [
                    'id' => $position->id,
                    'position_name' => $position->position_name,
                    'permissions' => $position->permissions->pluck('permission_name')->values(),
                ]),
            'gates' => $this->getAllGates(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('user.manage-users');

        User::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::defaults()],
            'position_id' => ['nullable', 'integer', Rule::exists(UserPositions::class, 'id')],
            'user_status' => ['nullable', 'string', Rule::in(['active', 'banned', 'deactivated'])],
        ]));

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('user.manage-users');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'password' => ['nullable', 'string', Password::defaults()],
            'position_id' => ['nullable', 'integer', Rule::exists(UserPositions::class, 'id')],
            'user_status' => ['nullable', 'string', Rule::in(['active', 'banned', 'deactivated'])],
        ]);

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $user->update($validated);

        return back()->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('user.manage-users');

        if ($request->user()?->is($user)) {
            return back()->withErrors([
                'user' => 'You cannot delete your own account from User Manager.',
            ]);
        }

        $user->delete();

        return back()->with('success', 'User deleted.');
    }

    public function storePosition(Request $request): RedirectResponse
    {
        Gate::authorize('user.manage-permissions');

        $validated = $request->validate([
            'position_name' => ['required', 'string', 'max:255', Rule::unique(UserPositions::class, 'position_name')],
            'permissions' => ['array'],
            'permissions.*' => ['required', 'string'],
        ]);

        DB::transaction(function () use ($validated): void {
            $position = UserPositions::create([
                'position_name' => $validated['position_name'],
                'position_code' => strtoupper(str_replace(' ', '_', $validated['position_name'])),
                'assigned_office' => 'Main Office',
                'is_active' => true,
            ]);

            $this->syncPermissions($position, $validated['permissions'] ?? []);
        });

        return back()->with('success', 'Position created.');
    }

    public function updatePosition(Request $request, UserPositions $position): RedirectResponse
    {
        Gate::authorize('user.manage-permissions');

        $validated = $request->validate([
            'position_name' => ['required', 'string', 'max:255', Rule::unique(UserPositions::class, 'position_name')->ignore($position->id)],
            'permissions' => ['array'],
            'permissions.*' => ['required', 'string'],
        ]);

        DB::transaction(function () use ($position, $validated): void {
            $position->update([
                'position_name' => $validated['position_name'],
                'position_code' => strtoupper(str_replace(' ', '_', $validated['position_name'])),
            ]);

            $this->syncPermissions($position, $validated['permissions'] ?? []);
        });

        return back()->with('success', 'Position permissions updated.');
    }

    public function destroyPosition(UserPositions $position): RedirectResponse
    {
        Gate::authorize('user.manage-permissions');

        if ($position->user()->exists()) {
            return back()->withErrors([
                'position' => 'Move users out of this position before deleting it.',
            ]);
        }

        $position->delete();

        return back()->with('success', 'Position deleted.');
    }

    protected function syncPermissions(UserPositions $position, array $permissions): void
    {
        UserPermissions::query()->where('position_id', $position->id)->delete();

        foreach (array_values(array_unique($permissions)) as $permission) {
            UserPermissions::create([
                'position_id' => $position->id,
                'permission_name' => $permission,
            ]);
        }
    }
}
