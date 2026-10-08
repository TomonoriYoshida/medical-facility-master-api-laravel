<?php

use App\Http\Middleware\BlockScanners;
use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();
        $middleware->append(SetSecurityHeaders::class);
        // Inside the CORS and security headers (so a blocked browser client
        // can read why), but before routing, the rate limiter and the app.
        $middleware->append(BlockScanners::class);

        // The only web routes are the root redirect and the read-only docs,
        // which need no session, cookies or CSRF protection; starting a
        // session there would write a sessions row for every visitor.
        $middleware->web(remove: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The API's errors in Japanese, like its validation messages. The
        // default 404 message also names the model class (e.g. "No query
        // results for model [App\Models\MedicalFacility] 1") even with debug
        // off. The exception's headers (429's Retry-After) are kept.
        $messages = [
            404 => '見つかりません。',
            405 => 'このURLは GET だけに対応しています。',
            429 => 'リクエストが多すぎます。しばらく待ってから、もう一度お試しください。',
        ];

        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $request->is('api/*') && isset($messages[$e->getStatusCode()])
            ? response()->json(['message' => $messages[$e->getStatusCode()]], $e->getStatusCode(), $e->getHeaders())
            : null);
    })->create();
