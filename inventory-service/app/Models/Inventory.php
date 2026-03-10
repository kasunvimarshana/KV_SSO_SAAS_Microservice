<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inventory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'product_id', 'warehouse',
        'quantity', 'reserved', 'reorder_level', 'reorder_quantity',
    ];

    protected $casts = [
        'quantity'        => 'integer',
        'reserved'        => 'integer',
        'reorder_level'   => 'integer',
        'reorder_quantity'=> 'integer',
    ];

    public function getAvailableAttribute(): int
    {
        return max(0, $this->quantity - $this->reserved);
    }

    public function isLowStock(): bool
    {
        return $this->available <= $this->reorder_level;
    }
}
