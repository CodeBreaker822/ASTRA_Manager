<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;

    protected function upgradeRequired(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'upgrade' => true,
        ], 402);
    }
}
