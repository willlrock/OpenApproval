<?php

namespace App\Services\Tracker;

use App\Jobs\Tracker\SendTrackerTelegramJob;
use App\Models\Tracker\Activity;
use App\Models\Tracker\Notification;
use App\Models\Tracker\Project;
use App\Models\Tracker\Task;

class TrackerService
{
    /**
     * Append an entry to the project/task activity log (§13). Append-only audit.
     */
    public function activity(Project $project, ?Task $task, ?int $actorId, string $type, array $changes = []): Activity
    {
        return Activity::create([
            'project_id' => $project->id,
            'task_id' => $task?->id,
            'actor_id' => $actorId,
            'type' => $type,
            'changes' => $changes ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Add a user as a watcher of a task (idempotent).
     */
    public function watch(Task $task, int $userId): void
    {
        $task->watchers()->syncWithoutDetaching([$userId]);
    }

    /**
     * Create in-app notifications for the given users (skips the actor).
     *
     * @param  iterable<int>  $userIds
     */
    public function notify(iterable $userIds, string $type, array $data, ?int $exceptUserId = null): void
    {
        if (! config('tracker.enabled', false)) {
            return;
        }

        $rows = [];
        $seen = [];
        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            if (! $uid || $uid === $exceptUserId || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $rows[] = [
                'user_id' => $uid,
                'type' => $type,
                'data' => json_encode($data),
                'read_at' => null,
                'created_at' => now(),
            ];
        }

        if ($rows) {
            Notification::insert($rows);

            // Mirror to Telegram on the queue (§20, D7). actor already excluded.
            SendTrackerTelegramJob::dispatch(array_keys($seen), $type, $data);
        }
    }
}
