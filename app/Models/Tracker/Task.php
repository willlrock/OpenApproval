<?php

namespace App\Models\Tracker;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    protected $table = 'tracker_tasks';

    protected $fillable = [
        'project_id',
        'status_id',
        'parent_id',
        'task_number',
        'title',
        'description',
        'position',
        'priority',
        'start_date',
        'due_date',
        'estimate',
        'story_points',
        'cover_type',
        'cover_value',
        'source',
        'form_submission_id',
        'is_archived',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'position' => 'float',
        'start_date' => 'datetime',
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
        'is_archived' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tracker_task_user', 'task_id', 'user_id')
            ->withTimestamps();
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tracker_task_watcher', 'task_id', 'user_id')
            ->withTimestamps();
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'tracker_task_label', 'task_id', 'label_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class, 'task_id')->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id')->whereNull('parent_id')->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'task_id')->latest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'task_id')->latest();
    }
}
