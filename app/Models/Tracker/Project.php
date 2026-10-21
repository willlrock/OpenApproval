<?php

namespace App\Models\Tracker;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $table = 'tracker_projects';

    protected $fillable = [
        'workspace_id',
        'space_id',
        'folder_id',
        'name',
        'color',
        'icon',
        'description',
        'background',
        'visibility',
        'agile_mode',
        'settings',
        'position',
        'default_view_id',
        'status_overview',
        'created_by',
        'archived_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'agile_mode' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class, 'project_id')->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class, 'project_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'project_id')->latest();
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tracker_project_user', 'project_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function roleFor(User $user): ?string
    {
        return $this->members()->where('users.id', $user->id)->first()?->pivot->role;
    }
}
