<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'external_id', 'name', 'email', 'role',
        'phone', 'address', 'metadata', 'is_active',
        'total_orders', 'total_spent', 'last_order_at',
    ];

    protected $casts = [
        'metadata'      => 'array',
        'is_active'     => 'boolean',
        'total_spent'   => 'decimal:2',
        'last_order_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Ensure tenant scope
        static::creating(function (self $user) {
            if (empty($user->total_orders)) {
                $user->total_orders = 0;
            }
            if (empty($user->total_spent)) {
                $user->total_spent = 0.00;
            }
        });
    }
}
