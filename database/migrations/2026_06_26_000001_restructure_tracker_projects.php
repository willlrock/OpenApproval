<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Task Tracker v2 restructure (TZ v2 §3, §13).
 | Renames the MVP entities Board→Project, List→Status, Card→Task and
 | introduces the Space/Folder layer between Workspace and Project.
 | Additive + rename only: touches the tracker_ tables exclusively, so the
 | rest of the platform (approval/supply/finance) is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. New layers: Space, Space membership, Folder.
        Schema::create('tracker_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('tracker_workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('#6366f1');
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->decimal('position', 16, 6)->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tracker_space_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('tracker_spaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['space_admin', 'space_member', 'space_guest'])->default('space_member');
            $table->timestamps();
            $table->unique(['space_id', 'user_id']);
        });

        Schema::create('tracker_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('tracker_spaces')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('position', 16, 6)->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        // 2. Rename tables Board→Project, List→Status, Card→Task (+ pivots).
        $this->renameTable('tracker_boards', 'tracker_projects');
        $this->renameTable('tracker_board_user', 'tracker_project_user');
        $this->renameTable('tracker_lists', 'tracker_statuses');
        $this->renameTable('tracker_cards', 'tracker_tasks');
        $this->renameTable('tracker_card_user', 'tracker_task_user');
        $this->renameTable('tracker_card_label', 'tracker_task_label');
        $this->renameTable('tracker_card_watcher', 'tracker_task_watcher');

        // 3. Rename FK columns to match the new vocabulary.
        $this->renameColumn('tracker_project_user', 'board_id', 'project_id');
        $this->renameColumn('tracker_statuses', 'board_id', 'project_id');
        $this->renameColumn('tracker_tasks', 'board_id', 'project_id');
        $this->renameColumn('tracker_tasks', 'list_id', 'status_id');
        $this->renameColumn('tracker_tasks', 'card_number', 'task_number');
        $this->renameColumn('tracker_task_user', 'card_id', 'task_id');
        $this->renameColumn('tracker_task_label', 'card_id', 'task_id');
        $this->renameColumn('tracker_task_watcher', 'card_id', 'task_id');
        $this->renameColumn('tracker_labels', 'board_id', 'project_id');
        $this->renameColumn('tracker_checklists', 'card_id', 'task_id');
        $this->renameColumn('tracker_comments', 'card_id', 'task_id');
        $this->renameColumn('tracker_activities', 'board_id', 'project_id');
        $this->renameColumn('tracker_activities', 'card_id', 'task_id');
        $this->renameColumn('tracker_attachments', 'card_id', 'task_id');

        // 4. New Project columns (Space link + color/icon + view defaults).
        Schema::table('tracker_projects', function (Blueprint $table) {
            $table->foreignId('space_id')->nullable()->after('workspace_id')
                ->constrained('tracker_spaces')->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->after('space_id')
                ->constrained('tracker_folders')->nullOnDelete();
            $table->string('color')->default('#6366f1')->after('name');
            $table->string('icon')->nullable()->after('color');
            $table->unsignedBigInteger('default_view_id')->nullable()->after('icon');
            $table->string('status_overview')->nullable()->after('default_view_id');
        });

        // 5. New Task columns (form intake source, TZ §11/§13).
        Schema::table('tracker_tasks', function (Blueprint $table) {
            $table->enum('source', ['manual', 'form'])->default('manual')->after('cover_value');
            $table->unsignedBigInteger('form_submission_id')->nullable()->after('source');
        });

        // 6. Free the project-member role column so values can move from the
        //    board_* vocabulary to project_* (TZ §13). The enum became a CHECK
        //    constraint (board_admin/board_member/board_observer) that survived
        //    the table rename — relax it to a plain string on both drivers.
        Schema::table('tracker_project_user', function (Blueprint $table) {
            $table->string('role')->default('project_member')->change();
        });

        // Postgres keeps the old CHECK under its pre-rename name; drop it too.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tracker_project_user DROP CONSTRAINT IF EXISTS tracker_board_user_role_check');
        }
    }

    public function down(): void
    {
        Schema::table('tracker_tasks', function (Blueprint $table) {
            $table->dropColumn(['source', 'form_submission_id']);
        });

        Schema::table('tracker_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('space_id');
            $table->dropConstrainedForeignId('folder_id');
            $table->dropColumn(['color', 'icon', 'default_view_id', 'status_overview']);
        });

        $this->renameColumn('tracker_attachments', 'task_id', 'card_id');
        $this->renameColumn('tracker_activities', 'task_id', 'card_id');
        $this->renameColumn('tracker_activities', 'project_id', 'board_id');
        $this->renameColumn('tracker_comments', 'task_id', 'card_id');
        $this->renameColumn('tracker_checklists', 'task_id', 'card_id');
        $this->renameColumn('tracker_labels', 'project_id', 'board_id');
        $this->renameColumn('tracker_task_watcher', 'task_id', 'card_id');
        $this->renameColumn('tracker_task_label', 'task_id', 'card_id');
        $this->renameColumn('tracker_task_user', 'task_id', 'card_id');
        $this->renameColumn('tracker_tasks', 'task_number', 'card_number');
        $this->renameColumn('tracker_tasks', 'status_id', 'list_id');
        $this->renameColumn('tracker_tasks', 'project_id', 'board_id');
        $this->renameColumn('tracker_statuses', 'project_id', 'board_id');
        $this->renameColumn('tracker_project_user', 'project_id', 'board_id');

        $this->renameTable('tracker_task_watcher', 'tracker_card_watcher');
        $this->renameTable('tracker_task_label', 'tracker_card_label');
        $this->renameTable('tracker_task_user', 'tracker_card_user');
        $this->renameTable('tracker_tasks', 'tracker_cards');
        $this->renameTable('tracker_statuses', 'tracker_lists');
        $this->renameTable('tracker_project_user', 'tracker_board_user');
        $this->renameTable('tracker_projects', 'tracker_boards');

        Schema::dropIfExists('tracker_folders');
        Schema::dropIfExists('tracker_space_user');
        Schema::dropIfExists('tracker_spaces');
    }

    private function renameTable(string $from, string $to): void
    {
        if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }

    private function renameColumn(string $table, string $from, string $to): void
    {
        if (Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            Schema::table($table, function (Blueprint $t) use ($from, $to) {
                $t->renameColumn($from, $to);
            });
        }
    }
};
