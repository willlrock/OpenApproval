<?php

namespace App\Models\Tracker;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Space extends Model
{
    protected $table = 'tracker_spaces';

    protected $fillable = [
        'workspace_id',
        'name',
        'color',
        'icon',
        'description',
        'position',
        'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class, 'space_id')->orderBy('position');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'space_id')->orderBy('position');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tracker_space_user', 'space_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function roleFor(User $user): ?string
    {
        return $this->members()->where('users.id', $user->id)->first()?->pivot->role;
    }
}
