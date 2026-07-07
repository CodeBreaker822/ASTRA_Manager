<?php

namespace App\Http\Controllers;

use App\Models\UserPermissions;
use App\Traits\Gates;
use Illuminate\Http\Request;

class UserPermissionsController extends Controller
{
    use Gates;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $userPerm = UserPermissions::all();

        // Get all available gates from your Gates classes
        $gates = $this->getAllGates();

        return view('settings.permission', compact('userPerm', 'gates'));

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validate = $request->validate([
            'position' => 'required',
            'permissions' => 'required|array',
        ]);

        UserPermissions::create([
            'position' => $validate['position'],
            'permissions' => $validate['permissions'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Permission created successfully',
        ]);

    }

    /**
     * Display the specified resource.
     */
    public function get()
    {
        $userPerm = UserPermissions::all();

        return response()->json([
            'success' => 'true',
            'data' => $userPerm,
        ]);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $userPermissions = UserPermissions::findOrFail($id);

        $validate = $request->validate([
            'position' => 'required',
            'permissions' => 'required|array',
        ]);

        $userPermissions->update([
            'position' => $validate['position'],
            'permissions' => $validate['permissions'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Permission updated successfully',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
        $userPermissions = UserPermissions::findOrFail($id);
        $userPermissions->delete();

        return response()->json([
            'success' => 'true',
            'message' => 'Permission deleted successfully',
        ]);

    }
}
