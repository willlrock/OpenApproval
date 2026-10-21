<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\SuppliersImport;
use App\Models\ApprovalRequest;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class SuppliersController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::withCount('approvalRequests');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('inn', 'like', "%{$search}%");
            });
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->active === '1');
        }

        $suppliers = $query->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'inn' => $s->inn,
                'description' => $s->description,
                'is_active' => $s->is_active,
                'requests_count' => $s->approval_requests_count,
                'created_at' => $s->created_at?->format('d.m.Y'),
            ]);

        return Inertia::render('Dashboard/Suppliers', [
            'suppliers' => $suppliers,
            'filters' => $request->only(['search', 'active']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:suppliers,name',
            'inn' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
        ], $this->validationMessages());

        Supplier::create([
            'name' => $request->name,
            'inn' => $request->inn,
            'description' => $request->description,
            'is_active' => true,
        ]);

        return back()->with('success', 'Поставщик добавлен');
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255|unique:suppliers,name,' . $id,
            'inn' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], $this->validationMessages());

        $supplier->update([
            'name' => $request->name,
            'inn' => $request->inn,
            'description' => $request->description,
            'is_active' => $request->boolean('is_active', $supplier->is_active),
        ]);

        return back()->with('success', 'Поставщик обновлён');
    }

    public function destroy($id)
    {
        $supplier = Supplier::findOrFail($id);

        if ($supplier->approvalRequests()->exists()) {
            return back()->withErrors(['error' => 'Нельзя удалить поставщика — к нему привязаны заявки.']);
        }

        $supplier->delete();
        return back()->with('success', 'Поставщик удалён');
    }

    private function validationMessages(): array
    {
        return [
            'name.required' => 'Укажите название поставщика.',
            'name.string' => 'Название поставщика должно быть текстом.',
            'name.max' => 'Название поставщика должно содержать не больше 255 символов.',
            'name.unique' => 'Поставщик с таким названием уже существует.',
            'inn.string' => 'ИНН должен быть текстом.',
            'inn.max' => 'ИНН должен содержать не больше 20 символов.',
            'description.string' => 'Описание должно быть текстом.',
            'description.max' => 'Описание должно содержать не больше 500 символов.',
            'is_active.boolean' => 'Выберите корректный статус поставщика.',
        ];
    }

    /**
     * Import suppliers from Excel file.
     * Expected columns: name (required), inn (optional), description (optional)
     */
    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
        ]);

        $import = new SuppliersImport();
        $rows = Excel::toArray($import, $request->file('file'));

        if (empty($rows) || empty($rows[0])) {
            return back()->withErrors(['file' => 'Файл пуст или не содержит данных.']);
        }

        $data = $rows[0];
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($data as $index => $row) {
            $rowNum = $index + 2; // +2 because row 1 is header

            $name = trim($row['name'] ?? $row['название'] ?? $row['наименование'] ?? '');
            if (!$name) {
                $errors[] = "Строка {$rowNum}: пропущена (нет названия)";
                $skipped++;
                continue;
            }

            if (Supplier::where('name', $name)->exists()) {
                $skipped++;
                continue;
            }

            $inn = trim($row['inn'] ?? $row['инн'] ?? '');
            $description = trim($row['description'] ?? $row['описание'] ?? '');

            Supplier::create([
                'name' => $name,
                'inn' => $inn ?: null,
                'description' => $description ?: null,
                'is_active' => true,
            ]);
            $imported++;
        }

        $message = "Импортировано: {$imported}";
        if ($skipped > 0) {
            $message .= ", пропущено: {$skipped}";
        }

        return back()->with('success', $message);
    }

    /**
     * Attach an existing supplier (or create from request's supplier_name) to an approval request.
     */
    public function attachToRequest(Request $request, $requestId)
    {
        $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'create_from_name' => 'nullable|boolean',
            'inn' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $requestId) {
        $approvalRequest = app(\App\Services\ApprovalRequestAccess::class)
            ->scopeForParticipation(ApprovalRequest::query(), $request->user())->lockForUpdate()->findOrFail($requestId);
        abort_unless(in_array($approvalRequest->status, ['pending_supply', 'rejected'], true), 409, 'Реквизиты согласуемой или оплаченной заявки изменять нельзя.');

        if ($request->filled('supplier_id')) {
            $supplier = Supplier::findOrFail($request->supplier_id);
            $approvalRequest->update(['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'supplier_inn' => $supplier->inn]);
        } elseif ($request->boolean('create_from_name') && $approvalRequest->supplier_name) {
            $supplier = Supplier::firstOrCreate(
                ['name' => $approvalRequest->supplier_name],
                [
                    'inn' => $request->inn,
                    'description' => $request->description,
                    'is_active' => true,
                ]
            );
            $approvalRequest->update(['supplier_id' => $supplier->id]);
        }

        return back()->with('success', 'Поставщик прикреплён к заявке');
        });
    }
}
