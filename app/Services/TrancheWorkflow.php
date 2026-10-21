<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TrancheWorkflow
{
    public function update(User $user, int $requestId, int $trancheId, array $data, ?UploadedFile $proof = null, ?string $telegramProof = null, bool $recordEffect = true): array
    {
        \Illuminate\Support\Facades\Validator::make($data, [
            'status' => 'required|in:pending,paid,failed', 'comment' => 'nullable|string|max:1000',
            'failure_reason' => 'nullable|string|max:1000',
        ])->validate();

        $storedProof = null;
        try {
            return DB::transaction(function () use ($user, $requestId, $trancheId, $data, $proof, $telegramProof, $recordEffect, &$storedProof) {
                $query = app(ApprovalRequestAccess::class)->financialQuery(ApprovalRequest::query(), $user);
                $request = $query->lockForUpdate()->findOrFail($requestId);
                $tranche = $request->tranches()->lockForUpdate()->findOrFail($trancheId);
                $oldStatus = $tranche->status;
                $oldRequestStatus = $request->status;
                if (isset($data['amount']) && round((float) $data['amount'] * 100) !== round((float) $tranche->amount * 100)) {
                    throw ValidationException::withMessages(['amount' => 'Сумма утверждённого транша зафиксирована. Изменение требует нового согласования.']);
                }
                if ($oldStatus === 'paid') {
                    throw ValidationException::withMessages(['status' => 'Оплаченный транш изменять нельзя.']);
                }
                if ($data['status'] === 'failed' && ($oldStatus !== 'failed' || array_key_exists('failure_reason', $data))
                    && ! trim($data['failure_reason'] ?? '')) {
                    throw ValidationException::withMessages(['failure_reason' => 'Укажите причину отказа в оплате.']);
                }
                if ($data['status'] === 'paid' && round((float) $request->tranches()->sum('amount') * 100) !== round((float) $request->total_price * 100)) {
                    throw ValidationException::withMessages(['amount' => 'Сумма графика платежей не совпадает с утверждённой суммой заявки.']);
                }
                if (array_key_exists('due_date', $data)) {
                    $requested = $data['due_date'] ? \Carbon\Carbon::parse($data['due_date'])->toDateString() : null;
                    if ($requested !== $tranche->due_date?->toDateString()) {
                        throw ValidationException::withMessages(['due_date' => 'Согласованный срок зафиксирован. Укажите новую обещанную дату отдельно.']);
                    }
                }
                $changes = array_intersect_key($data, array_flip(['status', 'comment', 'failure_reason']));
                if (array_key_exists('promised_date', $data)) {
                    $promise = ($data['promised_date'] ?? null) ?: null;
                    \Illuminate\Support\Facades\Validator::make(['promised_date' => $promise, 'reschedule_reason' => $data['reschedule_reason'] ?? null], [
                        'promised_date' => 'nullable|date_format:Y-m-d', 'reschedule_reason' => 'nullable|string|max:1000',
                    ])->validate();
                    if ($promise !== $tranche->promised_date?->toDateString()) {
                        if (! trim($data['reschedule_reason'] ?? '')) {
                            throw ValidationException::withMessages(['reschedule_reason' => 'Укажите причину изменения обещанного срока.']);
                        }
                        DB::table('tranche_deadline_changes')->insert(['request_tranche_id' => $tranche->id, 'user_id' => $user->id,
                            'previous_date' => $tranche->promised_date?->toDateString(), 'promised_date' => $promise,
                            'reason' => $data['reschedule_reason'], 'created_at' => now()]);
                        $changes['promised_date'] = $promise;
                    }
                }
                if (array_key_exists('payment_proof', $data) && $data['payment_proof'] !== $tranche->payment_proof) {
                    if (str_starts_with($data['payment_proof'] ?? '', 'payment_proofs/')) {
                        throw ValidationException::withMessages(['payment_proof' => 'Подтверждение следует загрузить файлом.']);
                    }
                    $changes['payment_proof'] = $data['payment_proof'];
                    $changes['payment_proof_telegram_id'] = null;
                }
                if ($proof) {
                    try {
                        $storedProof = $proof->store('payment_proofs', 'local');
                        if (! $storedProof || ! Storage::disk('local')->exists($storedProof)) {
                            throw \League\Flysystem\UnableToWriteFile::atLocation('payment_proofs');
                        }
                    } catch (\League\Flysystem\FilesystemException) {
                        throw ValidationException::withMessages([
                            'payment_proof_file' => 'Не удалось сохранить подтверждение оплаты. Статус платежа не изменён. Повторите загрузку.',
                        ]);
                    }
                    $changes['payment_proof'] = $storedProof;
                    $changes['payment_proof_telegram_id'] = null;
                }
                if ($telegramProof !== null) {
                    $changes['payment_proof'] = $telegramProof;
                    $changes['payment_proof_telegram_id'] = $telegramProof;
                }
                if ($data['status'] === 'paid') {
                    $changes['paid_at'] = now();
                }
                $tranche->update($changes);
                if ($request->tranches()->where('status', '!=', 'paid')->doesntExist()) {
                    $request->update(['status' => 'paid', 'pdf_url' => null]);
                }

                if ($tranche->wasChanged() && $recordEffect) {
                    app(RequestEffects::class)->record($request, 'tranche:'.$tranche->id.':'.\Illuminate\Support\Str::uuid(), [
                        'tranche_id' => $tranche->id, 'tranche_status' => $tranche->status, 'promised_date' => $tranche->promised_date?->toDateString(),
                        'tranche_amount' => $tranche->amount,
                        'reason' => $data['status'] === 'failed' ? ($data['failure_reason'] ?? $tranche->failure_reason) : ($data['reschedule_reason'] ?? null),
                        'proof' => (bool) $tranche->payment_proof_telegram_id || str_starts_with($tranche->payment_proof ?? '', 'payment_proofs/'),
                    ]);
                }
                if ($request->status === 'paid' && $oldRequestStatus !== 'paid') {
                    app(RequestEffects::class)->record($request, 'paid');
                }

                return [$request->load(['applicant', 'project']), $tranche, $oldStatus, $oldRequestStatus];
            });
        } catch (\Throwable $error) {
            if ($storedProof) {
                try {
                    if (! Storage::disk('local')->delete($storedProof)) {
                        Log::warning('Payment proof cleanup failed', ['request_id' => $requestId, 'path' => $storedProof]);
                    }
                } catch (\League\Flysystem\FilesystemException) {
                    Log::warning('Payment proof cleanup failed', ['request_id' => $requestId, 'path' => $storedProof]);
                }
            }
            throw $error;
        }
    }

    public function promise(User $actor, int $id, string $date, string $reason): void
    {
        DB::transaction(function () use ($actor, $id, $date, $reason) {
            $request = ApprovalRequest::lockForUpdate()->findOrFail($id);
            $tranches = $request->tranches()->where('status', '!=', 'paid')->get();
            abort_if($tranches->isEmpty(), 409);
            foreach ($tranches as $tranche) {
                $this->update($actor, $id, $tranche->id, ['status' => $tranche->status, 'promised_date' => $date, 'reschedule_reason' => $reason]);
            }
        });
    }

    public function rejectUnpaid(User $actor, int $id, string $reason): int
    {
        \Illuminate\Support\Facades\Validator::make(['reason' => $reason], ['reason' => 'required|string|max:1000'])->validate();

        return DB::transaction(function () use ($actor, $id, $reason) {
            $request = app(ApprovalRequestAccess::class)->financialQuery(ApprovalRequest::query(), $actor)
                ->where('status', 'approved')->lockForUpdate()->findOrFail($id);
            $pending = $request->tranches()->where('status', 'pending')->lockForUpdate()->pluck('id');
            foreach ($pending as $trancheId) {
                $this->update($actor, $id, $trancheId, ['status' => 'failed', 'failure_reason' => trim($reason)], recordEffect: false);
            }
            if ($pending->isNotEmpty()) {
                app(RequestEffects::class)->record($request, 'payment-rejected:'.\Illuminate\Support\Str::uuid(), [
                    'reason' => trim($reason), 'tranche_ids' => $pending->all(),
                ]);
            }

            return $pending->count();
        });
    }
}
