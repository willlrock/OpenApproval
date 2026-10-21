<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $attributes = ['is_active' => true];

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = $value === null ? null : trim($value);
        $this->attributes['phone_normalized'] = \App\Support\PhoneNumber::canonical($value);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'telegram_id',
        'username',
        'full_name',
        'title',
        'phone',
        'password',
        'role_id',
        'lang',
        'signature_path',
        'account_status',
        'is_active',
        'password_change_required',
        'password_change_due_at',
    ];

    public function getFirstNameAttribute(): string
    {
        return explode(' ', $this->full_name ?? '')[0] ?? '';
    }

    public function getLastNameAttribute(): string
    {
        $parts = explode(' ', $this->full_name ?? '');

        return $parts[1] ?? ($parts[0] ?? '');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'telegram_link_token_hash',
        'phone_normalized',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'role_id' => 'integer',
            'two_factor_confirmed_at' => 'datetime',
            'password_change_required' => 'boolean',
            'password_change_due_at' => 'immutable_datetime',
            'telegram_link_expires_at' => 'immutable_datetime',
        ];
    }

    // ── СТАТУСЫ АККАУНТА ─────────────────────────────────────────────────────

    public function isApproved(): bool
    {
        return $this->is_active && $this->account_status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->account_status === 'pending';
    }

    public function isRejected(): bool
    {
        return $this->account_status === 'rejected';
    }

    // ── РОЛИ ───────────────────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function isFinanceDirector(): bool
    {
        return in_array($this->role, ['superadmin', 'admin']);
    }

    public function isSupplyDirector(): bool
    {
        return in_array($this->role, ['supplier', 'superadmin']);
    }

    public function isAccountant(): bool
    {
        return in_array($this->role, ['accountant', 'admin', 'superadmin']);
    }

    public function isTreasurer(): bool
    {
        return in_array($this->role, ['treasurer', 'admin', 'superadmin']);
    }

    public function hasRole(string|array $roles): bool
    {
        $roles = (array) $roles;

        return in_array($this->role, $roles);
    }

    /**
     * Вход в панель открыт всем подтверждённым пользователям.
     * Видимость заявок и права действий проверяются отдельно.
     */
    public function canViewApprovalDashboard(): bool
    {
        return $this->isApproved();
    }

    public function canViewAllRequests(): bool
    {
        return $this->isApproved() && $this->hasRole(['admin', 'superadmin', 'supplier']);
    }

    /**
     * Compatibility layer for string roles
     */
    public function getRoleAttribute(): string
    {
        return $this->roleRelation?->name ?? 'requester';
    }

    // ── СВЯЗИ ──────────────────────────────────────────────────────────────

    public function roleRelation()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'applicant_id');
    }

    public function requestApprovals(): HasMany
    {
        return $this->hasMany(RequestApproval::class, 'approver_id');
    }

    // ── TASK TRACKER ─────────────────────────────────────────────────────────

    public function isTrackerAdmin(): bool
    {
        return in_array($this->role, ['superadmin', 'admin']);
    }

    public function trackerWorkspaces(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Tracker\Workspace::class,
            'tracker_workspace_user',
            'user_id',
            'workspace_id'
        )->withPivot('role')->withTimestamps();
    }
}
