<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'is_super_admin',
        'is_active',
        'is_stock_manager_enabled',
        'is_demo',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'is_stock_manager_enabled' => 'boolean',
            'is_demo' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * EVE SSO users have no password. SessionGuard compares this value to the
     * remember-me cookie with hash_equals(), which throws a TypeError on null.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->password;
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function stationConfigs(): HasMany
    {
        return $this->hasMany(StationConfig::class);
    }

    public function reactionPlans(): HasMany
    {
        return $this->hasMany(ReactionPlan::class);
    }

    public function inventionPlans(): HasMany
    {
        return $this->hasMany(InventionPlan::class);
    }

    public function buildPrograms(): HasMany
    {
        return $this->hasMany(BuildProgram::class);
    }

    public function mainCharacter(): HasOne
    {
        return $this->hasOne(Character::class)->where('is_main', true);
    }

    public function isAdmin(): bool
    {
        return $this->is_admin || $this->is_super_admin;
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin;
    }

    public function isDemo(): bool
    {
        return (bool) $this->is_demo;
    }

    public function hasStockManager(): bool
    {
        return (bool) $this->is_stock_manager_enabled;
    }

    public function industryStockItems(): HasMany
    {
        return $this->hasMany(IndustryStockItem::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->mainCharacter?->name ?? $this->name;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->mainCharacter?->portrait_url;
    }
}
