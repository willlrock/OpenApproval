<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ к разделам панели согласований («Заявки», «Мои заявки»,
 * «Мои согласования»). Доступен всем подтверждённым пользователям;
 * видимость конкретных заявок проверяется отдельно.
 */
class CheckApprovalDashboardAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->canViewApprovalDashboard()) {
            abort(403, 'У вас нет доступа к этому разделу.');
        }

        return $next($request);
    }
}
