<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::get('/health/ready', \App\Http\Controllers\ReadinessController::class)->name('health.ready');
            Route::middleware('web')->group(__DIR__.'/../routes/tracker.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\LogRequestContext::class);
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustConfiguredProxies::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            \App\Http\Middleware\EnsureApprovedAccount::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\CheckWebRole::class,
            'approval.dashboard' => \App\Http\Middleware\CheckApprovalDashboardAccess::class,
            'tracker.access' => \App\Http\Middleware\Tracker\EnsureTrackerAccess::class,
            'workspace.member' => \App\Http\Middleware\Tracker\EnsureWorkspaceAccess::class,
            'project.member' => \App\Http\Middleware\Tracker\EnsureProjectAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        \Sentry\Laravel\Integration::handles($exceptions);
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if ($id = request()->attributes->get('correlation_id')) {
                $response->headers->set('X-Correlation-ID', $id);
            }

            return $response;
        });
    })->create();
