<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'user_id', 'status', 'total_amount',
        'shipping_address', 'notes', 'saga_id',
        'confirmed_at', 'completed_at', 'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'total_amount'   => 'decimal:2',
        'confirmed_at'   => 'datetime',
        'completed_at'   => 'datetime',
        'cancelled_at'   => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function sagaLog(): HasMany
    {
        return $this->hasMany(SagaLog::class);
    }
}
