<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $fillable = ['name', 'email', 'password', 'role_id', 'active'];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function rts()
    {
        return $this->belongsToMany(Rt::class, 'operator_rt');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->active && in_array($permission, $this->role?->permissions ?? [], true);
    }

    public function requirePermission(string $permission): void
    {
        abort_unless($this->hasPermission($permission), 403, 'Anda tidak memiliki izin untuk tindakan ini.');
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $this->role?->slug === 'operator' ? $query->whereIn('rt_id', $this->rts()->select('rts.id')) : $query;
    }

    public function canAccessCustomer(Customer $customer): bool
    {
        return $this->active && ($this->role?->slug !== 'operator' || $this->rts()->where('rts.id', $customer->rt_id)->exists());
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }
}
