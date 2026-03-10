<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'tenant_id', 'order_id', 'product_id',
        'quantity', 'unit_price', 'total_price',
        'inventory_id', 'product_name', 'product_code',
    ];

    protected $casts = [
        'unit_price'  => 'decimal:2',
        'total_price' => 'decimal:2',
        'quantity'    => 'integer',
    ];
}
