<?php

namespace App\Http\Controllers;

use App\Services\ApprovalRouteRecovery;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApprovalRouteRecoveryController extends Controller
{
    public function edit(Request $http, int $id, ApprovalRouteRecovery $recovery)
    {
        return Inertia::render('RequestDrafts/ApprovalRoute', ['request' => $recovery->editor($id, $http->user())]);
    }

    public function save(Request $http, int $id, ApprovalRouteRecovery $recovery)
    {
        $recovery->save($id, $http->user(), $http->only(['revision', 'sequence', 'approvers']));

        return back()->with('success', 'Маршрут сохранён. Отправьте заявку на повторное согласование.');
    }

    public function submit(Request $http, int $id, ApprovalRouteRecovery $recovery)
    {
        $recovery->submit($id, $http->user(), $http->only(['revision', 'sequence']));

        return to_route('requests.detail', $id)->with('success', 'Заявка отправлена на повторное согласование.');
    }
}
