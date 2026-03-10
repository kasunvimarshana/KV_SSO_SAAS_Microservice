<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('external_id'); // ID from auth service
            $table->string('name');
            $table->string('email');
            $table->string('role')->default('staff');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('total_orders')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0.00);
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'email']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
