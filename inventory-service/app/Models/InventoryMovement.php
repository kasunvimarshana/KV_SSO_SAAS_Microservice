<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    public $timestamps = true;
    public const UPDATED_AT = null; // Only created_at

    protected $fillable = [
        'tenant_id', 'inventory_id', 'product_id',
        'type', 'quantity', 'reference', 'saga_id', 'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];
}
