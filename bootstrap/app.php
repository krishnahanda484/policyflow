<?php

use App\Exceptions\ConcurrentModification;
use App\Exceptions\InvalidStateTransition;
use App\Exceptions\IssuanceNotAllowed;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Consistent JSON error envelope: {"message": "...", "error": "<code>"} (+ "errors" for 422).
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                // Keep abort(404, 'custom message') messages, hide model internals.
                $message = $e instanceof NotFoundHttpException && $e->getMessage() !== '' ? $e->getMessage() : 'Resource not found.';

                return response()->json(['message' => $message, 'error' => 'not_found'], 404);
            }
        });
        $exceptions->render(fn (InvalidStateTransition $e) => response()->json(['message' => $e->getMessage(), 'error' => 'invalid_state_transition'], 409));
        $exceptions->render(fn (IssuanceNotAllowed $e) => response()->json(['message' => $e->getMessage(), 'error' => 'issuance_not_allowed'], 409));
        $exceptions->render(fn (ConcurrentModification $e) => response()->json(['message' => 'The request was modified concurrently; retry.', 'error' => 'concurrent_modification'], 409));
    })->create();
