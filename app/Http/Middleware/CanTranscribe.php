<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Billing\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanTranscribe
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $feature = $request->routeIs('workspace.chunk') ? 'live' : 'upload';

        if (! app(EntitlementService::class)->canAfford($user, $feature, 1)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Insufficient balance. Please add funds to continue.',
                    'upgrade' => true,
                ], 402);
            }

            return back()->with('error', 'Insufficient balance. Please add funds to continue.');
        }

        return $next($request);
    }
}
