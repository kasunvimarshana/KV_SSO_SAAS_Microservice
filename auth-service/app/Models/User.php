<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'tenant_id', 'role',
        'permissions', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'permissions'   => 'array',
        'is_active'     => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function toSafeArray(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'email'         => $this->email,
            'role'          => $this->role,
            'permissions'   => $this->permissions ?? [],
            'tenant_id'     => $this->tenant_id,
            'tenant_code'   => $this->tenant?->code,
            'tenant_name'   => $this->tenant?->name,
            'is_active'     => $this->is_active,
            'last_login_at' => $this->last_login_at,
            'created_at'    => $this->created_at,
        ];
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles);
    }
}
