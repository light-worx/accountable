<?php

namespace App\Models;

use App\Enums\EntityRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class)->withPivot('role')->withTimestamps();
    }

    public function roleIn(Entity $entity): ?EntityRole
    {
        $role = $this->entities()->whereKey($entity->getKey())->first()?->pivot?->role;

        return $role ? EntityRole::tryFrom($role) : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true; // access to data is controlled per entity (tenant) below
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->entities()->where('entities.is_active', true)->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->entities()->whereKey($tenant->getKey())->exists();
    }
}
