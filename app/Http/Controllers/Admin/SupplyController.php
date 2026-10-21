<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequestsRequest;
use App\Models\ApprovalRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplyController extends Controller
{
    /**
     * Дашборд директора снабжения
     */
    public function dashboard()
    {
        $pendingSupply = $this->query()->where('status', 'pending_supply')->count();
        $forwardedCount = $this->query()->whereNotNull('supply_applicant_id')
            ->where('status', 'pending_approval')->count();
        $approvedCount = $this->query()->whereNotNull('supply_applicant_id')
            ->where('status', 'approved')->count();
        $rejectedCount = $this->query()->whereNotNull('supply_applicant_id')
            ->where('status', 'rejected')->count();
        $cancelledCount = $this->query()->where('status', 'cancelled')->count();

        $recentRequests = $this->query()->with(['project', 'applicant'])
            ->where(function ($q) {
                $q->where('status', 'pending_supply')
                    ->orWhere('status', 'cancelled')
                    ->orWhereNotNull('supply_applicant_id');
            })
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => $this->formatRequest($r));

        return Inertia::render('Supply/Dashboard', [
            'stats' => [
                'pendingSupply' => $pendingSupply,
                'forwardedCount' => $forwardedCount,
                'approvedCount' => $approvedCount,
                'rejectedCount' => $rejectedCount,
                'cancelledCount' => $cancelledCount,
            ],
            'recentRequests' => $recentRequests,
        ]);
    }

    /**
     * Список заявок на снабжение
     */
    public function requests(SearchRequestsRequest $request)
    {
        $query = $this->query()->with(['project', 'applicant', 'items']);

        // Только заявки снабжения: pending_supply, cancelled ИЛИ сконвертированные (supply_applicant_id)
        $query->where(function ($q) {
            $q->where('status', 'pending_supply')
                ->orWhere('status', 'cancelled')
                ->orWhereNotNull('supply_applicant_id');
        });

        if ($request->filled('status')) {
            if ($request->status === 'pending_supply') {
                $query->where('status', 'pending_supply');
            } elseif ($request->status === 'forwarded') {
                $query->whereNotNull('supply_applicant_id')->where('status', 'pending_approval');
            } elseif ($request->status === 'approved') {
                $query->whereNotNull('supply_applicant_id')->where('status', 'approved');
            } elseif ($request->status === 'rejected') {
                $query->whereNotNull('supply_applicant_id')->where('status', 'rejected');
            } elseif ($request->status === 'cancelled') {
                $query->where('status', 'cancelled');
            }
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('search')) {
            $query->search($request->string('search')->toString(), ['name']);
        }

        $requests = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($r) => $this->formatRequest($r));

        $projects = app(\App\Services\ApprovalRequestCreation::class)->projects(auth()->user())->map->only(['id', 'name']);

        return Inertia::render('Supply/Requests', [
            'requests' => $requests,
            'projects' => $projects,
            'filters' => $request->only(['status', 'project_id', 'search']),
        ]);
    }

    /**
     * Детали заявки на снабжение
     */
    public function requestDetail(Request $http, int $id)
    {
        $request = $this->query()->findOrFail($id);
        abort_unless($request->type === 'supply' || $request->supply_applicant_id, 403);

        return redirect()->route('requests.detail', [
            'id' => $request->id, 'section' => 'supply',
            'back' => $http->query('back', '/supply/requests'),
        ]);
    }

    /**
     * Обновить количество позиции
     */
    public function updateItemQuantity(Request $request, $requestId, $itemId)
    {
        app(\App\Services\SupplyWorkflow::class)->updateQuantity((int) $requestId, (int) $itemId, $request->user(), $request->all());

        return back()->with('success', 'Количество обновлено');
    }

    /**
     * Конвертировать заявку на снабжение в заявку на расход
     */
    public function convert(Request $request, $id)
    {
        app(\App\Services\SupplyWorkflow::class)->convert((int) $id, $request->user(), $request->all());

        return redirect()->route('supply.requests')->with('success', 'Заявка передана на финансирование');
    }

    /**
     * Получить список доступных согласующих
     */
    public function approvers(Request $http)
    {
        $http->validate(['request_id' => 'required|integer']);
        $request = app(\App\Services\ApprovalRequestAccess::class)->scope(ApprovalRequest::query(), $http->user())->findOrFail($http->integer('request_id'));
        $approvers = app(\App\Services\ApprovalParticipantPolicy::class)->eligible($request->project_id, [$request->applicant_id, $request->supply_applicant_id, $http->user()->id], $request->isManagementDocument())
            ->orderBy('full_name')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->full_name,
                'title' => $u->title,
            ]);

        return response()->json($approvers);
    }

    /**
     * Отменить заявку на снабжение
     */
    public function cancel(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000', 'revision' => 'required|integer|min:1']);
        app(\App\Services\SupplyWorkflow::class)->cancel((int) $id, $request->user(), $data['reason'], (int) $data['revision']);

        return redirect()->route('supply.requests')->with('success', 'Заявка отменена');
    }

    /**
     * Скачать позиции заявки в Excel
     */
    public function exportRequestItems($id)
    {
        $approvalRequest = $this->query()->with('items')->findOrFail($id);

        if ($approvalRequest->type !== 'supply' && is_null($approvalRequest->supply_applicant_id)) {
            abort(403, 'Эта заявка не относится к снабжению.');
        }

        if ($approvalRequest->items->isEmpty()) {
            return back()->withErrors(['error' => 'У заявки нет позиций для экспорта.']);
        }

        $export = new \App\Exports\RequestItemsExport($approvalRequest);
        $filename = "request_{$id}_items.xlsx";

        return \Maatwebsite\Excel\Facades\Excel::download($export, $filename);
    }

    /**
     * Поиск поставщиков по имени (API)
     */
    public function searchSuppliers(Request $request)
    {
        $query = $request->get('q', '');

        $suppliers = \App\Models\Supplier::query()
            ->where('is_active', true)
            ->when($query, fn ($q) => $q->where('name', 'LIKE', '%'.$query.'%'))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'inn']);

        return response()->json($suppliers);
    }

    /**
     * Создать нового поставщика (API)
     */
    public function storeSupplier(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'inn' => 'nullable|string|max:50',
        ]);

        $supplier = \App\Models\Supplier::firstOrCreate(
            ['name' => $validated['name']],
            ['inn' => $validated['inn'] ?? null]
        );

        return response()->json($supplier);
    }

    private function query()
    {
        return app(\App\Services\SupplyWorkflow::class)->query(auth()->user());
    }

    protected function formatRequest($r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'name' => $r->name,
            'status' => $r->status,
            'currency' => $r->currency,
            'total_price' => $r->total_price,
            'supplier_name' => $r->supplier_name,
            'created_at' => $r->created_at->format('d.m.Y H:i'),
            'project' => $r->project?->name,
            'applicant' => $r->applicant?->full_name,
            'applicant_phone' => $r->applicant?->phone,
            'items_count' => $r->items?->count() ?? 0,
        ];
    }
}
