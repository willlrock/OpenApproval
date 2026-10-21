<?php

namespace App\Http\Middleware\Tracker;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureTrackerAccess
{
    /**
     * Tracker entry gate (§4.3). A logged-in platform user may open /tracker,
     * but sees work only after a workspace invitation. Tracker Admins bypass.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('tracker.enabled', false), 404);

        $user = $request->user();

        $hasAccess = $user && ($user->isTrackerAdmin() || $user->trackerWorkspaces()->exists());

        if (! $hasAccess) {
            if ($request->expectsJson()) {
                abort(403, 'Вы не приглашены ни в одно рабочее пространство.');
            }

            return Inertia::render('tracker/NotInvited')->toResponse($request);
        }

        return $next($request);
    }
}
