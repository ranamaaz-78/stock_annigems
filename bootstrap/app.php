<?php

use App\Http\Middleware\ApplySettingsSession;
use App\Http\Middleware\EnsureBranchMember;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SetBranchContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Application bootstrap — routing, middleware aliases, and exception rendering.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'superadmin' => EnsureSuperAdmin::class,
            'password.changed' => EnsurePasswordChanged::class,
            'branch.context' => SetBranchContext::class,
            'branch.member' => EnsureBranchMember::class,
        ]);

        // Idle timeout follows SESSION_LIFETIME, not a SuperAdmin setting.
        $middleware->appendToGroup('web', ApplySettingsSession::class);

        $middleware->redirectGuestsTo(fn () => route('login'));

        $middleware->redirectUsersTo(function () {
            $user = auth()->user();

            if ($user?->must_change_password) {
                return route('password.change');
            }

            return $user?->isSuperAdmin()
                ? route('superadmin.dashboard')
                : route('branch.dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render branded error pages for HTTP exceptions when expecting HTML.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null;

            if ($status === null) {
                return null;
            }

            $views = [
                401 => 'errors.401',
                403 => 'errors.403',
                404 => 'errors.404',
                419 => 'errors.419',
                429 => 'errors.429',
                500 => 'errors.500',
                503 => 'errors.503',
            ];

            if (isset($views[$status]) && view()->exists($views[$status])) {
                return response()->view($views[$status], ['exception' => $e], $status);
            }

            return null;
        });
    })->create();
