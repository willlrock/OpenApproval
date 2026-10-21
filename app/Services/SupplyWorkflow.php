<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\ApprovalRequestPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplyWorkflow
{
    public function query(User $actor)
    {
        abort_unless($actor->fresh()->isApproved() && $actor->fresh()->isSupplyDirector(), 403);

        return app(ApprovalRequestAccess::class)->scope(ApprovalRequest::query(), $actor->fresh())
            ->where(fn ($q) => $q->where('type', 'supply')->orWhereNotNull('supply_applicant_id'));
    }

    public function pending(int $id, User $actor, ?int $revision = null): ApprovalRequest
    {
        $request = $this->query($actor)->findOrFail($id);
        $this->assertPending($request, $actor, $revision);

        return $request;
    }

    private function assertPending(ApprovalRequest $request, User $actor, ?int $revision): void
    {
        abort_unless(app(ApprovalRequestPolicy::class)->manageSupply($actor, $request), 403);
        if ($request->type !== 'supply' || $request->status !== 'pending_supply'
            || ($revision !== null && $request->revision !== $revision)) {
            throw ValidationException::withMessages(['error' => 'Заявка изменилась или уже обработана. Откройте её заново.']);
        }
    }

    public function updateQuantity(int $id, int $itemId, User $actor, array $input): void
    {
        $data = Validator::make($input, ['quantity' => 'required|numeric|min:0.001|max:100000000|decimal:0,3', 'revision' => 'required|integer|min:1'])->validate();
        DB::transaction(function () use ($id, $itemId, $actor, $data) {
            app(ApprovalParticipantPolicy::class)->lockWorkflow();
            $request = $this->query($actor)->lockForUpdate()->findOrFail($id);
            $this->assertPending($request, $actor, (int) $data['revision']);
            app(RequestHistory::class)->archive($request);
            $request->items()->findOrFail($itemId)->update(['quantity' => $data['quantity'], 'price_per_unit' => null, 'total_price' => null]);
            $request->update(['revision' => $request->revision + 1]);
        });
    }

    public function cancel(int $id, User $actor, string $reason, ?int $revision = null): ApprovalRequest
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:1000'])->validate();

        return DB::transaction(function () use ($id, $actor, $reason, $revision) {
            app(ApprovalParticipantPolicy::class)->lockWorkflow();
            $request = $this->query($actor)->lockForUpdate()->findOrFail($id);
            $this->assertPending($request, $actor, $revision);
            $request->update(['status' => 'cancelled', 'cancellation_reason' => $reason, 'cancelled_by' => $actor->id, 'cancelled_at' => now()]);
            app(RequestEffects::class)->record($request, 'cancelled', ['reason' => $reason]);

            return $request;
        });
    }

    public function convert(int $id, User $actor, array $input): ApprovalRequest
    {
        $data = Validator::make($input, [
            'revision' => 'required|integer|min:1', 'currency' => 'required|in:USD,RUB,EUR,UZS',
            'supplier_name' => 'nullable|required_without:supplier_id|string|max:255',
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'transaction_type' => 'required|in:cash,bank',
            'deadline_from_supplier' => 'nullable|date_format:Y-m-d', 'deadline_date' => 'nullable|date_format:Y-m-d',
            'factual_delivery_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'approvers' => 'required|array|min:1|max:30', 'approvers.*' => 'integer|distinct',
            'items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|numeric|min:0.001|max:100000000|decimal:0,3',
            'items.*.price_per_unit' => 'required|numeric|min:0.01|max:1000000000|decimal:0,2',
            'tranches' => 'nullable|array|max:50', 'tranches.*.amount' => 'required|numeric|min:0.01|max:9999999999999|decimal:0,2',
            'tranches.*.due_date' => 'required|date_format:Y-m-d', 'tranches.*.comment' => 'nullable|string|max:1000',
        ])->validate();

        return DB::transaction(function () use ($id, $actor, $data) {
            app(ApprovalParticipantPolicy::class)->lockWorkflow();
            app(ApprovalParticipantPolicy::class)->lockParticipants([$actor->id, ...$data['approvers']]);
            $request = $this->query($actor)->lockForUpdate()->findOrFail($id);
            $this->assertPending($request, $actor, (int) $data['revision']);
            $expected = $request->items()->pluck('id')->sort()->values()->all();
            $received = collect($data['items'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($expected !== $received || $request->tranches()->where('status', 'paid')->exists()) {
                throw ValidationException::withMessages(['items' => 'Передайте все позиции заявки ровно один раз. Оплаченные заявки изменять нельзя.']);
            }
            app(ApprovalParticipantPolicy::class)->assertChain($request->project_id, $data['approvers'], [$actor->id, $request->applicant_id]);
            $actor = $actor->fresh();
            abort_unless(app(\App\Policies\ApprovalRequestPolicy::class)->manageSupply($actor, $request), 403);
            app(ApprovalParticipantPolicy::class)->assertChain($request->project_id, $data['approvers'], [$actor->id, $request->applicant_id]);
            $totalCents = array_sum(array_map(fn ($i) => (int) round($i['quantity'] * $i['price_per_unit'] * 100), $data['items']));
            if ($totalCents <= 0 || $totalCents > 999999999999999) {
                throw ValidationException::withMessages(['items' => 'Проверьте итоговую сумму заявки.']);
            }
            $tranches = ($data['tranches'] ?? []) ?: [['amount' => $totalCents / 100,
                'due_date' => $data['deadline_date'] ?? $request->deadline_date?->toDateString(), 'comment' => 'Единовременный платёж']];
            if (array_sum(array_map(fn ($t) => (int) round($t['amount'] * 100), $tranches)) !== $totalCents) {
                throw ValidationException::withMessages(['tranches' => 'Сумма платежей должна точно совпадать с суммой позиций.']);
            }
            app(RequestHistory::class)->archive($request);
            foreach ($data['items'] as $item) {
                $request->items()->whereKey($item['id'])->update(['quantity' => $item['quantity'], 'price_per_unit' => $item['price_per_unit'],
                    'total_price' => round($item['quantity'] * $item['price_per_unit'] * 100) / 100]);
            }
            $supplier = ! empty($data['supplier_id']) ? Supplier::findOrFail($data['supplier_id']) : Supplier::firstOrCreate(['name' => $data['supplier_name']]);
            abort_unless($supplier->fresh()->is_active, 422, 'Поставщик неактивен.');
            $request->update([
                'type' => 'approval', 'status' => 'pending_approval', 'currency' => $data['currency'],
                'supplier_name' => $supplier->name, 'supplier_inn' => $supplier->inn, 'supplier_id' => $supplier->id,
                'transaction_type' => $data['transaction_type'], 'total_price' => $totalCents / 100, 'expected_approval_level' => 1,
                'supply_applicant_id' => $request->applicant_id, 'applicant_id' => $actor->id,
                'deadline_date' => $data['deadline_date'] ?? $request->deadline_date,
                'deadline_from_supplier' => $data['deadline_from_supplier'] ?? null, 'factual_delivery_date' => $data['factual_delivery_date'] ?? null,
                'revision' => $request->revision + 1, 'pdf_url' => null,
                'applicant_signature_snapshot_path' => null, 'applicant_signature_sha256' => null,
            ]);
            $request->unsetRelation('applicant');
            if ($actor->fresh()->signature_path) {
                app(ManagementDocumentService::class)->captureApplicantSignature($request);
            }
            $request->approvals()->delete();
            foreach ($data['approvers'] as $index => $approverId) {
                $request->approvals()->create(['approver_id' => $approverId, 'level' => $index + 1, 'status' => 'pending']);
            }
            $request->tranches()->delete();
            foreach ($tranches as $tranche) {
                $request->tranches()->create(['amount' => $tranche['amount'], 'due_date' => $tranche['due_date'],
                    'comment' => $tranche['comment'] ?? null, 'percent' => round($tranche['amount'] * 10000 / $totalCents, 2), 'status' => 'pending']);
            }
            app(RequestCreationNotifier::class)->schedule($request);
            app(RequestEffects::class)->record($request, 'converted');

            return $request->fresh();
        });
    }
}
