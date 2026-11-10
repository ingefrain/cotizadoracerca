<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'session_version' => 'integer',
            'last_activity_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function avatar(): MorphOne
    {
        return $this->morphOne(Document::class, 'documentable')->where('category', 'user.avatar')->latestOfMany();
    }

    public function hasPermissionTo(string $permission): bool
    {
        return $this->is_active
            && $this->roles()->where('roles.is_active', true)
                ->whereHas('permissions', fn ($query) => $query->where('permissions.key', $permission))
                ->exists();
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        if (! $this->is_active) {
            return [];
        }

        return $this->roles()
            ->where('roles.is_active', true)
            ->with('permissions:id,key')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('key'))
            ->unique()
            ->values()
            ->all();
    }
}
