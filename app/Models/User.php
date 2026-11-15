<?php

namespace App\Models;

use App\Enums\EntityRole;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class User extends Authenticatable implements FilamentUser, HasTenants, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasFactory, Notifiable, LogsActivity;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;

    // is_admin is only ever changed from the /system area, which only administrators can open.
    // Do NOT add it to any form that ordinary users can reach.
    protected $fillable = ['name', 'email', 'password', 'is_admin', 'is_active'];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_admin', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('access');
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class)->withPivot('role')->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(EntityUser::class);
    }

    public function roleIn(Entity $entity): ?EntityRole
    {
        $role = $this->entities()->whereKey($entity->getKey())->first()?->pivot?->role;

        return $role ? EntityRole::tryFrom($role) : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->is_active === false) {
            return false;
        }

        return match ($panel->getId()) {
            'system' => (bool) $this->is_admin,
            default => true, // people with no society yet are sent to the set-up page
        };
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->entities()->where('entities.is_active', true)->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->entities()
            ->whereKey($tenant->getKey())
            ->where('entities.is_active', true)
            ->exists();
    }
}