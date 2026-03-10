<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SagaLog extends Model
{
    protected $fillable = [
        'saga_id', 'tenant_id', 'order_id', 'status',
        'steps', 'compensations', 'error', 'retry_count',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'steps'        => 'array',
        'compensations'=> 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];
}
