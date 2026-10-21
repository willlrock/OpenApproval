<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | Data backfill for the v2 restructure (TZ v2 §16): migrate, don't reset.
 | Per existing workspace create a default "General" space, move its projects
 | under it, mirror workspace membership into space membership, and colour the
 | statuses. No-ops on a fresh DB (e.g. the test suite) where there is no data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $statusColors = [
            'backlog' => '#94a3b8',
            'todo' => '#3b82f6',
            'in_progress' => '#f59e0b',
            'done' => '#22c55e',
        ];

        foreach (DB::table('tracker_workspaces')->get() as $workspace) {
            // 1. One default Space per workspace.
            $spaceId = DB::table('tracker_spaces')->insertGetId([
                'workspace_id' => $workspace->id,
                'name' => 'General',
                'color' => '#6366f1',
                'icon' => null,
                'description' => null,
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 2. Mirror workspace membership into the space.
            $members = DB::table('tracker_workspace_user')
                ->where('workspace_id', $workspace->id)->get();

            foreach ($members as $m) {
                DB::table('tracker_space_user')->insert([
                    'space_id' => $spaceId,
                    'user_id' => $m->user_id,
                    'role' => match ($m->role) {
                        'workspace_admin' => 'space_admin',
                        'workspace_guest' => 'space_guest',
                        default => 'space_member',
                    },
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 3. Move this workspace's projects under the default space.
            DB::table('tracker_projects')
                ->where('workspace_id', $workspace->id)
                ->update(['space_id' => $spaceId]);
        }

        // 4. Colour statuses by their category, only where still on the old
        //    monochrome default (or unset) so user-picked colours survive.
        foreach ($statusColors as $category => $color) {
            DB::table('tracker_statuses')
                ->where('status_category', $category)
                ->where(function ($q) {
                    $q->whereNull('color')
                        ->orWhereIn('color', ['#9ca3af', '#94a3b8', '']);
                })
                ->update(['color' => $color]);
        }

        // 5. Existing tasks are manual entries (column already defaults to it).
        DB::table('tracker_tasks')->whereNull('source')->update(['source' => 'manual']);

        // 6. Move project membership roles to the project_* vocabulary (TZ §13).
        //    board_admin/board_member/board_observer already map 1:1.
        foreach ([
            'board_admin' => 'project_admin',
            'board_member' => 'project_member',
            'board_observer' => 'project_observer',
        ] as $old => $new) {
            DB::table('tracker_project_user')->where('role', $old)->update(['role' => $new]);
        }
    }

    public function down(): void
    {
        // Spaces are dropped by the structural migration's down(); detach links.
        DB::table('tracker_projects')->update(['space_id' => null, 'folder_id' => null]);
    }
};
