<?php

namespace App\Jobs\APIController;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreApiTokenRequest;
use App\Http\Requests\Api\UpdateApiMethodRequest;
use App\Http\Requests\Api\UpdateApiStatusRequest;
use App\Models\API;
use App\Services\Api\ApiTokenService;
use App\Services\LicenseKeyService;
use Illuminate\Http\JsonResponse;

class ApiTokenController extends Controller
{
    public function generateLicenseKey(LicenseKeyService $licenses): JsonResponse
    {
        return response()->json([
            'success' => true,
            'license_key' => $licenses->makeUniqueLicenseKey(),
        ]);
    }

    public function store(StoreApiTokenRequest $request, ApiTokenService $tokens): JsonResponse
    {
        $created = $tokens->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'API settings saved successfully!',
            'data' => $created['api'],
            'plain_token' => $created['plain_token'],
        ]);
    }

    public function updateStatus(UpdateApiStatusRequest $request, API $api, ApiTokenService $tokens): JsonResponse
    {
        $api = $tokens->updateStatus($api, (bool) $request->validated('is_active'));

        return response()->json([
            'success' => true,
            'message' => 'API status updated successfully!',
            'data' => $tokens->present($api),
        ]);
    }

    public function updateMethod(UpdateApiMethodRequest $request, API $api, ApiTokenService $tokens): JsonResponse
    {
        $validated = $request->validated();
        $api = $tokens->updateMethod($api, (string) $validated['method'], (bool) $validated['enabled']);

        return response()->json([
            'success' => true,
            'message' => 'API method updated successfully!',
            'data' => $tokens->present($api),
        ]);
    }

    public function destroy(API $api, ApiTokenService $tokens): JsonResponse
    {
        $tokens->delete($api);

        return response()->json([
            'success' => true,
            'message' => 'API deleted successfully!',
        ]);
    }
}
