<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Address\CityMunicipality;
use App\Models\Address\Province;
use App\Models\Address\Region;
use App\Models\User;
use App\Models\UserPermissions;
use App\Models\UserPositions;
use App\Services\AuditLogService;
use App\Traits\Gates;
use App\Traits\ImageTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserAuthController extends Controller
{
    use Gates;
    use ImageTrait;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $query = User::with('position')->select('*');

        // Search functionality
        if (request()->has('search')) {
            $searchTerm = request('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('biometric_id', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('assigned_office', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Position filter
        if (request()->has('positions') && request('positions') !== 'all') {
            $query->whereHas('position', function ($q) {
                $q->where('position_name', request('positions'));
            });
        }

        // Employee status filter
        if (request()->has('employee_status') && request('employee_status') !== 'all') {
            $query->where('user_status', request('employee_status'));
        }

        $users = $query->paginate(10)->withQueryString();
        $userCount = $users->total();
        $positions = UserPositions::all();
        $regions = Region::all();

        return view('settings.users', compact('users', 'userCount', 'positions', 'regions'))->with('_pagination', [
            'links' => $users->links()->render(),
            'elements' => [
                'previous' => __('pagination.previous'),
                'next' => __('pagination.next'),
            ],
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function permissions()
    {
        $users = UserPositions::with('permissions')->get();
        $gates = $this->getAllGates();

        return view('settings.permission', compact('users', 'gates'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function storePermission(Request $request)
    {
        $validate = $request->validate([
            'position' => 'required|string|max:255',
            'permissions' => 'required|array',
        ]);

        // Create new position
        $position = UserPositions::create([
            'position_name' => $validate['position'],
            'position_code' => strtoupper(str_replace(' ', '_', $validate['position'])),
            'assigned_office' => Auth::user()->assigned_office ?? 'Main Office',
            'is_active' => true,
        ]);

        // Create permissions for the new position
        foreach ($validate['permissions'] as $permission) {
            UserPermissions::create([
                'position_id' => $position->id,
                'permission_name' => $permission,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Position and permissions created successfully',
        ]);

    }

    public function getPermissions()
    {
        $positions = UserPositions::with('permissions')->get();

        $data = $positions->map(function ($position) {
            return [
                'id' => $position->id,
                'position_name' => $position->position_name,
                'permissions' => $position->permissions->pluck('permission_name')->toArray(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'biometric_id' => 'nullable|string|max:255',
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'position' => 'nullable|exists:user_positions,id',
                'user_status' => 'nullable|string|max:255',
                'region' => 'nullable|string|max:255',
                'province' => 'nullable|string|max:255',
                'city_municipality' => 'nullable|string|max:255',
                'profile_picture_base64' => 'nullable|string',
                'designation' => 'nullable|string|max:255',
            ]);

            // Check for unique biometric_id (excluding current user) — only if provided
            if (! empty($validated['biometric_id'])) {
                $existingBiometric = User::where('biometric_id', $validated['biometric_id'])
                    ->where('id', '!=', $id)
                    ->first();

                if ($existingBiometric) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This biometric ID is already assigned to another user',
                    ], 422);
                }
            }

            // Check for unique email (excluding current user)
            $existingEmail = User::where('email', $validated['email'])
                ->where('id', '!=', $id)
                ->first();

            if ($existingEmail) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This email is already assigned to another user',
                ], 422);
            }

            // Capture old values for audit log
            $oldValues = [
                'name' => $user->name,
                'email' => $user->email,
                'biometric_id' => $user->biometric_id,
                'position_id' => $user->position_id,
                'user_status' => $user->user_status,
                'assigned_office' => $user->assigned_office,
                'designation' => $user->profile_data['designation'] ?? null,
            ];

            // Update user fields
            $user->biometric_id = $validated['biometric_id'] ?? null;
            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->position_id = $validated['position'] ?? null;
            $user->user_status = $validated['user_status'] ?? null;

            // Get address names from IDs using model relationships
            $locationNames = [];

            if (! empty($validated['region'])) {
                $region = Region::where('regCode', $validated['region'])->first();
                if ($region) {
                    $locationNames[] = $region->regDesc;

                    // If province is selected, get it through relationship
                    if (! empty($validated['province'])) {
                        $province = $region->provinces()->where('provCode', $validated['province'])->first();
                        if ($province) {
                            $locationNames[] = $province->provDesc;

                            // If city is selected, get it through relationship
                            if (! empty($validated['city_municipality'])) {
                                $city = $province->cityMunicipalities()->where('citymunCode', $validated['city_municipality'])->first();
                                if ($city) {
                                    $locationNames[] = $city->citymunDesc;
                                }
                            }
                        }
                    }
                }
            }

            // Create location string for assigned_office using names
            $user->assigned_office = implode(', ', $locationNames);

            // Update profile_data - handle as array like ProfileController
            $profileData = is_array($user->profile_data) ? $user->profile_data : [];
            $profileData['region'] = $validated['region'] ?? null;
            $profileData['province'] = $validated['province'] ?? null;
            $profileData['city_municipality'] = $validated['city_municipality'] ?? null;

            // Update designation in profile_data
            $profileData['designation'] = $validated['designation'] ?? null;

            // Handle profile picture — store as file in storage/app/private/profile_pictures/
            if ($request->filled('profile_picture_base64')) {
                $base64Image = $validated['profile_picture_base64'];
                $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));

                // Determine file extension from mime type
                preg_match('#data:image/(\w+);base64,#i', $base64Image, $matches);
                $extension = isset($matches[1]) ? strtolower($matches[1]) : 'jpg';
                if ($extension === 'jpeg') $extension = 'jpg';

                // Generate filename: uniquenumber-fullname-dateadded
                $uniqueNumber = Str::random(8);
                $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', str_replace(' ', '', $user->name));
                $dateAdded = now()->format('Ymd-His');
                $filename = "{$uniqueNumber}-{$cleanName}-{$dateAdded}.{$extension}";

                // Delete old profile picture if exists
                $oldPath = $profileData['profile_picture_path'] ?? null;
                if ($oldPath) {
                    Storage::disk('local')->delete($oldPath);
                }

                // Store the new image
                $path = 'profile_pictures/' . $filename;
                Storage::disk('local')->put($path, $imageData);
                $profileData['profile_picture_path'] = $path;

                // Remove legacy base64 from profile_data if it exists
                unset($profileData['profile_picture_base64']);
            }

            $user->profile_data = $profileData;
            $user->save();

            // Audit log
            $newValues = [
                'name' => $user->name,
                'email' => $user->email,
                'biometric_id' => $user->biometric_id,
                'position_id' => $user->position_id,
                'user_status' => $user->user_status,
                'assigned_office' => $user->assigned_office,
                'designation' => $profileData['designation'] ?? null,
            ];

            $auditService = app(AuditLogService::class);
            $auditService->log([
                'event' => 'user_updated',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'description' => "User '{$user->name}' updated by ".auth()->user()->name,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'severity' => 'medium',
                'module' => 'User Management',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error updating user: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'User deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error deleting user: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function updatePermission(Request $request, $id)
    {
        $position = UserPositions::findOrFail($id);

        $validate = $request->validate([
            'permissions' => 'required|array',
        ]);

        // Delete all existing permissions for this position
        UserPermissions::where('position_id', $position->id)->delete();

        // Create new permissions
        foreach ($validate['permissions'] as $permission) {
            UserPermissions::create([
                'position_id' => $position->id,
                'permission_name' => $permission,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Permissions updated successfully',
        ]);
    }

    /**
     * Get provinces for a given region
     */
    public function getProvinces($regionCode)
    {
        $provinces = Province::where('regCode', $regionCode)->get();

        return response()->json($provinces);
    }

    /**
     * Get cities for a given province
     */
    public function getCities($provinceCode)
    {
        $cities = CityMunicipality::where('provCode', $provinceCode)->get();

        return response()->json($cities);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroyPermission(Request $request, $id)
    {
        $position = UserPositions::findOrFail($id);

        // Delete all permissions for this position
        UserPermissions::where('position_id', $position->id)->delete();

        // Also delete the position itself
        $position->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Position and permissions deleted successfully',
        ]);

    }

    /**
     * Reset user preferences to default
     */
    public function resetPreferences($id)
    {
        try {
            $user = User::findOrFail($id);
            $auditService = app(AuditLogService::class);

            // Get current preferences for audit log
            $oldPreferences = $user->preferences ? $user->preferences->toArray() : null;

            // Delete user preferences (will revert to defaults)
            if ($user->preferences) {
                $user->preferences->delete();
            }

            // Audit log
            $auditService->logSecurity(
                'user_preferences_reset',
                'Administrator reset user preferences to default',
                [
                    'target_user_id' => $user->id,
                    'target_user_name' => $user->name,
                    'target_user_email' => $user->email,
                    'old_preferences' => $oldPreferences,
                    'reset_by' => Auth::user()->name,
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'User preferences reset to default successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error resetting user preferences: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reset preferences',
            ], 500);
        }
    }

    /**
     * Bulk delete users
     */
    public function bulkDelete(Request $request)
    {
        try {
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'integer|exists:users,id',
            ]);

            $ids = array_map('intval', $request->input('ids'));

            // Prevent deleting yourself
            $currentUserId = Auth::id();
            if (in_array($currentUserId, $ids, true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot delete your own account',
                ], 422);
            }

            $users = User::whereIn('id', $ids)->get();
            $deletedNames = $users->pluck('name')->toArray();
            $count = $users->count();

            User::whereIn('id', $ids)->delete();

            $auditService = app(AuditLogService::class);
            $auditService->log([
                'event' => 'users_bulk_deleted',
                'auditable_type' => User::class,
                'auditable_id' => null,
                'description' => "Bulk deleted {$count} user(s)",
                'severity' => 'critical',
                'module' => 'User Management',
                'metadata' => [
                    'deleted_count' => $count,
                    'deleted_user_names' => $deletedNames,
                    'deleted_by' => Auth::user()->name,
                ],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "{$count} user(s) deleted successfully",
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk delete users failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Error deleting users: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all user IDs matching current filters (for select-all-pages)
     */
    public function getAllIds(Request $request)
    {
        $query = User::query();

        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('biometric_id', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('assigned_office', 'LIKE', "%{$searchTerm}%");
            });
        }

        if ($request->has('positions') && $request->positions !== 'all') {
            $query->whereHas('position', function ($q) use ($request) {
                $q->where('position_name', $request->positions);
            });
        }

        if ($request->has('employee_status') && $request->employee_status !== 'all') {
            $query->where('user_status', $request->employee_status);
        }

        // Exclude current user from bulk operations
        $query->where('id', '!=', Auth::id());

        return response()->json([
            'ids' => $query->pluck('id')->toArray(),
        ]);
    }
}
