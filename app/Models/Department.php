<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['project_id', 'name', 'is_active'];

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function getForProject(?int $projectId): array
    {
        if ($projectId === null) return [];

        return static::where('project_id', $projectId)
            ->active()
            ->get()
            ->pluck('label', 'id')
            ->toArray();
    }

    public function getLabelAttribute(): string
    {
        return $this->name;
    }

    public static function activeList(): array
    {
        return static::where('is_active', true)
            ->get()
            ->map(fn($d) => $d->name)
            ->toArray();
    }
}
