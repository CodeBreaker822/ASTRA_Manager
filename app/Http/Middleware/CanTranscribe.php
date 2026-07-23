<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanTranscribe
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        if (! app(EntitlementService::class)->canTranscribe($user)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'You have used today\'s free transcription minutes. Buy more minutes to continue today.',
                    'upgrade' => true,
                ], 402);
            }

            return back()->with('error', 'You have used today\'s free transcription minutes. Buy more minutes to continue today.');
        }

        return $next($request);
    }
}
