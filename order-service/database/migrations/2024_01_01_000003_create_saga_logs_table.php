<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saga_logs', function (Blueprint $table) {
            $table->id();
            $table->string('saga_id')->unique();
            $table->string('tenant_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->enum('status', ['IN_PROGRESS', 'COMPLETED', 'FAILED', 'COMPENSATED', 'PENDING_RETRY'])
                ->default('IN_PROGRESS');
            $table->json('steps')->nullable();
            $table->json('compensations')->nullable();
            $table->text('error')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saga_logs');
    }
};
