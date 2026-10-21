<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApprovalRequestCreation
{
    public function projects(User $user)
    {
        return $user->hasRole(['admin', 'superadmin']) ? Project::where('is_active', true)->get()
            : $user->projects()->where('projects.is_active', true)->get();
    }

    public function approvers(int $projectId, ?int $authorId = null)
    {
        return app(ApprovalParticipantPolicy::class)->eligible($projectId, [$authorId])->orderBy('id')->get();
    }

    public function supplyApprovers(int $projectId, ?int $authorId = null)
    {
        $chain = User::where('account_status', 'approved')->where('is_active', true)->whereHas('roleRelation', fn ($r) => $r->where('name', 'approver'))
            ->join('project_user', fn ($j) => $j->on('project_user.user_id', '=', 'users.id')->where('project_user.project_id', $projectId))
            ->orderBy('project_user.id')->select('users.*')->get();
        $chain = $chain->reject(fn ($u) => $u->id === $authorId)->values();
        $eligible = app(ApprovalParticipantPolicy::class)->eligible($projectId, [$authorId]);
        $director = (clone $eligible)->whereHas('roleRelation', fn ($r) => $r->where('name', 'supplier'))->orderBy('id')->first()
            ?? (clone $eligible)->whereHas('roleRelation', fn ($r) => $r->where('name', 'superadmin'))->orderBy('id')->first();
        if (! $director) {
            return collect();
        }
        $chain->push($director);

        return $chain->unique('id')->values();
    }

    public function create(User $actor, array $input, array $attachments = [], ?int $editId = null, ?int $expectedRevision = null, bool $replaceAttachments = false): ApprovalRequest
    {
        abort_unless($actor->fresh()->isApproved(), 403);
        // Stored drafts bypass HTTP empty-string middleware when submitted later.
        array_walk_recursive($input, function (&$value) {
            if (is_string($value) && trim($value) === '') {
                $value = null;
            }
        });
        // Accept the same human-readable money format as the web editor.
        foreach (['items' => 'price_per_unit', 'tranches' => 'amount'] as $rows => $field) {
            if (! is_array($input[$rows] ?? null)) {
                continue;
            }
            foreach ($input[$rows] as &$row) {
                if (is_array($row) && is_string($row[$field] ?? null)) {
                    $row[$field] = str_replace(',', '.', preg_replace('/[\s\p{Z}]+/u', '', $row[$field]));
                    if ($row[$field] === '') {
                        $row[$field] = null;
                    }
                }
            }
            unset($row);
        }
        $supply = ($input['type'] ?? 'approval') === 'supply';
        if ($supply) {
            foreach (['currency', 'transaction_type', 'supplier_id', 'supplier_name', 'supplier_inn', 'approvers'] as $key) {
                $input[$key] = null;
            }
            $input['tranches'] = [];
            if (is_array($input['items'] ?? null)) {
                foreach ($input['items'] as &$item) {
                    if (is_array($item)) {
                        $item['price_per_unit'] = null;
                    }
                }
            }
            unset($item);
        }
        $data = Validator::make($input, [
            'type' => ['required', Rule::in(['approval', 'supply'])],
            'project_id' => ['required', 'integer', Rule::in($this->projects($actor)->pluck('id')->all())],
            'currency' => [$supply ? 'nullable' : 'required', Rule::in(['UZS', 'USD', 'EUR', 'RUB'])],
            'transaction_type' => [$supply ? 'nullable' : 'required', Rule::in(['cash', 'bank'])],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'supplier_name' => [$supply ? 'nullable' : 'required_without:supplier_id', 'nullable', 'string', 'max:255'],
            'supplier_inn' => ['nullable', 'string', 'max:30'],
            'deadline_date' => [$supply ? 'required' : 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:100000000', 'decimal:0,3'],
            'items.*.price_per_unit' => [$supply ? 'nullable' : 'required', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,2'],
            'approvers' => [$supply ? 'nullable' : 'required', 'array', 'min:1', 'max:30'],
            'approvers.*' => ['integer', 'distinct'],
            'tranches' => ['nullable', 'array', 'max:50'],
            'tranches.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999', 'decimal:0,2'],
            'tranches.*.due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'tranches.*.comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'required' => 'Заполните поле «:attribute».', 'required_without' => 'Заполните поле «:attribute».',
            'numeric' => 'В поле «:attribute» нужно число.', 'integer' => 'Некорректное значение поля «:attribute».',
            'min.numeric' => '«:attribute»: минимальное значение :min.', 'max.numeric' => '«:attribute»: максимальное значение :max.',
            'min.array' => 'Добавьте хотя бы один элемент: :attribute.', 'max.array' => 'Слишком много элементов: :attribute.',
            'decimal' => 'Проверьте количество знаков после запятой: :attribute.',
            'date_format' => 'Укажите корректную дату: :attribute.', 'after_or_equal' => 'Выберите сегодняшний или будущий срок: :attribute.',
            'in' => 'Недопустимое значение: :attribute.', 'exists' => 'Выбранное значение недоступно: :attribute.',
            'distinct' => 'Уберите повторяющихся согласующих.', 'project_id.in' => 'Проект недоступен.',
        ], ['project_id' => 'Проект', 'currency' => 'Валюта', 'supplier_name' => 'Поставщик', 'deadline_date' => 'Срок',
            'items' => 'Позиции', 'items.*.name' => 'Наименование позиции', 'items.*.quantity' => 'Количество', 'items.*.price_per_unit' => 'Цена',
            'approvers' => 'Согласующие', 'tranches' => 'График платежей', 'tranches.*.amount' => 'Сумма платежа', 'tranches.*.due_date' => 'Срок платежа'])->validate();
        // Resolve an omitted expense deadline at submission, since drafts can be saved for days.
        $data['deadline_date'] ??= today()->toDateString();
        $chain = $supply ? $this->supplyApprovers((int) $data['project_id'], $actor->id)->pluck('id')->all() : $data['approvers'];
        app(ApprovalParticipantPolicy::class)->assertChain((int) $data['project_id'], $chain, [$actor->id]);
        $totalCents = 0;
        foreach ($data['items'] as &$item) {
            $cents = $supply ? 0 : (int) round((float) $item['price_per_unit'] * (float) $item['quantity'] * 100);
            $item['total_price'] = $cents / 100;
            $totalCents += $cents;
        }
        unset($item);
        if (! $supply && ($totalCents <= 0 || $totalCents > 999999999999999)) {
            throw ValidationException::withMessages(['items' => 'Проверьте итоговую сумму заявки.']);
        }
        if (! $supply && ! empty($data['tranches']) && array_sum(array_map(fn ($t) => (int) round($t['amount'] * 100), $data['tranches'])) !== $totalCents) {
            throw ValidationException::withMessages(['tranches' => 'Сумма платежей должна точно совпадать с суммой позиций.']);
        }

        return DB::transaction(function () use ($actor, $data, $supply, $chain, $totalCents, $attachments, $editId, $expectedRevision, $replaceAttachments) {
            app(ApprovalParticipantPolicy::class)->lockWorkflow();
            app(ApprovalParticipantPolicy::class)->lockParticipants([$actor->id, ...$chain]);
            $request = $editId ? ApprovalRequest::whereKey($editId)->where('applicant_id', $actor->id)
                ->whereIn('status', ['rejected', 'revision_requested'])->where('type', '!=', 'management_document')->lockForUpdate()->firstOrFail() : null;
            $actor = $actor->fresh();
            abort_unless($actor->isApproved(), 403);
            app(ApprovalParticipantPolicy::class)->assertChain((int) $data['project_id'], $chain, [$actor->id]);
            $supplier = ! $supply && ! empty($data['supplier_id']) ? Supplier::findOrFail($data['supplier_id']) : null;
            $attributes = [
                'project_id' => $data['project_id'], 'name' => $data['items'][0]['name'],
                'currency' => $supply ? null : $data['currency'], 'total_price' => $supply ? null : $totalCents / 100,
                'transaction_type' => $supply ? null : $data['transaction_type'],
                'supplier_id' => $supplier?->id, 'supplier_name' => $supply ? null : ($supplier?->name ?? $data['supplier_name']),
                'supplier_inn' => $supply ? null : ($supplier?->inn ?? ($data['supplier_inn'] ?? null)),
                'status' => 'pending_approval', 'expected_approval_level' => 1, 'deadline_date' => $data['deadline_date'] ?? null,
            ];
            if ($editId) {
                abort_unless($request->revision === ($expectedRevision ?? 1), 409, 'Заявка изменилась. Начните редактирование заново.');
                abort_if($request->tranches()->where('status', 'paid')->exists(), 409);
                abort_unless($request->type === $data['type'] && ! $request->supply_applicant_id, 409, 'Тип или процесс заявки уже изменился.');
                app(RequestHistory::class)->archive($request);
                $attributes += ['revision' => $request->revision + 1, 'pdf_url' => null, 'applicant_signature_snapshot_path' => null, 'applicant_signature_sha256' => null];
                $request->update($attributes);
                if ($replaceAttachments) {
                    $request->attachments()->update(['archived_at' => now()]);
                }
                $request->approvals()->delete();
                $request->items()->delete();
                $request->tranches()->delete();
            } else {
                $request = ApprovalRequest::create($attributes + ['type' => $data['type'], 'applicant_id' => $actor->id]);
            }
            if ($actor->signature_path) {
                app(ManagementDocumentService::class)->captureApplicantSignature($request);
            }
            foreach ($data['items'] as $item) {
                $request->items()->create(['name' => $item['name'], 'description' => $item['description'] ?? null,
                    'quantity' => $item['quantity'], 'price_per_unit' => $supply ? null : $item['price_per_unit'], 'total_price' => $supply ? null : $item['total_price']]);
            }
            foreach ($chain as $index => $id) {
                $request->approvals()->create(['approver_id' => $id, 'level' => $index + 1, 'status' => 'pending']);
            }
            foreach ($attachments as $attachment) {
                $request->attachments()->create($attachment);
            }
            if (! $supply) {
                foreach (($data['tranches'] ?? []) ?: [['amount' => $totalCents / 100, 'due_date' => $data['deadline_date'] ?? null, 'comment' => 'Единовременный платёж']] as $tranche) {
                    $request->tranches()->create(['amount' => $tranche['amount'], 'due_date' => $tranche['due_date'], 'comment' => $tranche['comment'] ?? null, 'status' => 'pending', 'percent' => round($tranche['amount'] * 10000 / $totalCents, 2)]);
                }
            }
            app(RequestCreationNotifier::class)->schedule($request);

            return $request;
        });
    }
}
