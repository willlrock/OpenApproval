<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApprovalParticipantPolicy
{
    public function eligible(int $projectId, array $excluded = [], bool $signature = false): \Illuminate\Database\Eloquent\Builder
    {
        return User::where('account_status', 'approved')->where('is_active', true)
            ->whereHas('roleRelation', fn ($q) => $q->whereIn('name', ['admin', 'superadmin', 'approver', 'supplier']))
            ->whereHas('projects', fn ($q) => $q->where('projects.id', $projectId)->where('projects.is_active', true))
            ->whereNotIn('users.id', array_filter($excluded))
            ->when($signature, fn ($q) => $q->whereNotNull('signature_path'));
    }

    public function canDecide(ApprovalRequest $request, User $actor): bool
    {
        return $this->eligible($request->project_id, [$request->applicant_id, $request->supply_applicant_id], $request->isManagementDocument())->whereKey($actor->id)->exists();
    }

    public function assertCanDecide(ApprovalRequest $request, User $actor): void
    {
        if (! $this->canDecide($request, $actor)) {
            throw ValidationException::withMessages(['error' => 'Нет действующих полномочий в проекте. Автор не может согласовать собственную заявку.']);
        }
    }

    public function assertChain(int $projectId, array $ids, array $excluded = [], bool $signature = false): void
    {
        $eligible = $this->eligible($projectId, $excluded, $signature)->whereIn('users.id', $ids)->orderBy('users.id');
        if (! $ids || count(array_unique($ids)) !== count($ids) || $eligible->get(['users.id'])->count() !== count($ids)) {
            throw ValidationException::withMessages(['approvers' => 'Выберите независимых действующих согласующих, назначенных этому проекту. Если список пуст, администратору нужно настроить проект.']);
        }
    }

    /** Acquire before any user/request lock in route writers, decisions and access changes. */
    public function lockWorkflow(): void
    {
        app(UserAdministration::class)->lockAdministrators();
    }

    public function lockParticipants(array $ids): void
    {
        $this->lockWorkflow();
        // The shared workflow lock serializes access changes and route operations.
        // Participant rows are acquired in ascending order before the parent request.
        $ids = array_filter($ids, fn ($id) => (is_int($id) || is_string($id))
            && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false);
        User::whereKey(array_values(array_unique($ids)))->orderBy('id')->lockForUpdate()->get(['id']);
    }
}
