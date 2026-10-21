<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestDeletion
{
    public function delete(int $id, User $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($id);
            if (! $user->isApproved()
                || (! $user->hasRole(['admin', 'superadmin']) && $request->applicant_id !== $user->id)
                || ! in_array($request->status, ['pending_supply', 'pending_approval', 'rejected', 'cancelled'], true)
                || $request->tranches()->where('status', 'paid')->exists()) {
                throw ValidationException::withMessages(['error' => 'Эту заявку удалить нельзя: она уже обработана или содержит оплаченные транши.']);
            }
            $request->delete();
        });
    }
}
