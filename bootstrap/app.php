<?php

use App\Http\Middleware\CanTranscribe;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackPageVisit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: ['paymongo/webhook']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            TrackPageVisit::class,
        ]);

        $middleware->alias([
            'can.transcribe' => CanTranscribe::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            Log::error('HTTP upload rejected before controller because POST body is too large.', [
                'path' => $request->path(),
                'method' => $request->method(),
                'user_id' => $request->user()?->id,
                'content_length' => $request->server('CONTENT_LENGTH'),
                'content_type' => $request->headers->get('content-type'),
                'post_max_size' => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Audio upload could not be processed.'], 413);
            }

            return null;
        });
    })->create();
