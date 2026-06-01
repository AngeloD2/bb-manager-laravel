<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_loops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('parent_loop_id')->nullable();
            $table->boolean('is_fallback')->default(false);
            $table->boolean('is_global')->default(false);
            $table->integer('order_index')->default(0);
            $table->unsignedInteger('max_daily_spots')->nullable();    // loop-scoped daily cap
            $table->json('assigned_devices')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('media_loops', function (Blueprint $table) {
            $table->foreign('parent_loop_id')
                  ->references('id')
                  ->on('media_loops')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_loops');
    }
};
