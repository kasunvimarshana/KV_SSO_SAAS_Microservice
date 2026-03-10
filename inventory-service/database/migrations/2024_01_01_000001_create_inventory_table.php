<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('product_id');
            $table->string('warehouse')->default('main');
            $table->integer('quantity')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('reorder_level')->default(10);
            $table->integer('reorder_quantity')->default(50);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'product_id', 'warehouse']);
            $table->index(['tenant_id', 'product_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
