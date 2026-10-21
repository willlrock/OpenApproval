<?php

namespace App\Models\Tracker;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends Model
{
    use SoftDeletes;

    protected $table = 'tracker_workspaces';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'settings',
        'members_can_create_boards',
        'created_by',
    ];

    protected $casts = [
        'settings' => 'array',
        'members_can_create_boards' => 'boolean',
    ];

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class, 'workspace_id')->orderBy('position');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'workspace_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tracker_workspace_user', 'workspace_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function roleFor(User $user): ?string
    {
        return $this->members()->where('users.id', $user->id)->first()?->pivot->role;
    }
}
