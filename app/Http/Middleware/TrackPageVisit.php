<?php

namespace App\Http\Middleware;

use App\Models\PageVisitDailyStat;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TrackPageVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldTrack($request, $response)) {
            return $response;
        }

        try {
            $this->record($request);
        } catch (Throwable $exception) {
            Log::debug('Page visit analytics could not be recorded.', [
                'route' => $request->route()?->getName(),
                'exception' => $exception::class,
            ]);
        }

        return $response;
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || ! $response->isSuccessful()) {
            return false;
        }

        if ($request->expectsJson() || $request->route()?->getName() === null) {
            return false;
        }

        $contentType = (string) $response->headers->get('content-type');

        return str_contains($contentType, 'text/html') || $request->header('X-Inertia') === 'true';
    }

    private function record(Request $request): void
    {
        $route = $request->route();
        $routeName = Str::limit((string) $route?->getName(), 120, '');
        $routeUri = (string) $route?->uri();
        $isPrivateRoute = Str::startsWith($routeUri, ['dashboard', 'workspace', 'settings']);
        $path = $isPrivateRoute
            ? '/'.ltrim($routeUri, '/')
            : '/'.ltrim($request->path(), '/');
        $path = $path === '//' ? '/' : Str::limit($path, 255, '');
        $isAuthenticated = $request->user() !== null;
        $isBot = $this->isBot((string) $request->userAgent());

        $stat = PageVisitDailyStat::query()->firstOrCreate([
            'visit_date' => now()->startOfDay(),
            'path_hash' => hash('sha256', $path),
        ], [
            'route_name' => $routeName,
            'path' => $path,
        ]);

        $stat->incrementEach([
            'total_visits' => 1,
            $isAuthenticated ? 'authenticated_visits' : 'guest_visits' => 1,
            'bot_visits' => $isBot ? 1 : 0,
        ]);
    }

    private function isBot(string $userAgent): bool
    {
        return preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit/i', $userAgent) === 1;
    }
}
