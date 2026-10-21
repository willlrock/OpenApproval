<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectoryDeletion
{
    public function user(int $id, ?User $actor = null): void
    {
        throw ValidationException::withMessages(['error' => 'Удаление аккаунтов отключено. Деактивируйте аккаунт, чтобы сохранить историю.']);
    }

    public function deleteRegistrationPlaceholder(int $id): void
    {
        DB::transaction(function () use ($id) {
            $user = User::lockForUpdate()->findOrFail($id);
            if ($user->account_status !== 'new' || $user->role !== 'requester'
                || $user->full_name || $user->phone || $user->signature_path || $user->password
                || $user->approvalRequests()->withTrashed()->exists() || $user->requestApprovals()->exists()
                || $user->projects()->exists() || $user->departments()->exists()) {
                throw ValidationException::withMessages(['telegram' => 'Этот Telegram уже связан с аккаунтом. Обратитесь к администратору.']);
            }

            $user->delete();
        });
    }

    public function project(int $id): void
    {
        DB::transaction(function () use ($id) {
            $project = Project::lockForUpdate()->findOrFail($id);
            if (ApprovalRequest::withTrashed()->where('project_id', $id)->exists()) {
                throw ValidationException::withMessages(['error' => 'Проект содержит историю заявок. Сделайте проект неактивным вместо удаления.']);
            }
            $project->delete();
        });
    }
}
