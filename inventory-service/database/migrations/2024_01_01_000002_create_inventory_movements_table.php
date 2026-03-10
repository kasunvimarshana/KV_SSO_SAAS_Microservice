<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('inventory_id')->constrained('inventories')->onDelete('cascade');
            $table->string('product_id');
            $table->enum('type', ['initial', 'purchase', 'sale', 'adjustment', 'damage', 'return', 'reservation', 'release']);
            $table->integer('quantity'); // positive = add, negative = subtract
            $table->string('reference')->nullable();
            $table->string('saga_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'saga_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
