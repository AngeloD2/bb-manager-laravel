<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->uuid('loop_id')->nullable();
            $table->uuid('device_id');
            $table->unsignedTinyInteger('spot_spent')->default(1);
            $table->boolean('was_override')->default(false);
            $table->timestamp('played_at');

            // Indexes for the hot constraint-checking queries
            $table->index(['asset_id', 'played_at']);
            $table->index(['loop_id', 'played_at']);
            $table->index(['device_id', 'played_at']);

            $table->foreign('asset_id')->references('id')->on('media_assets')->cascadeOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_logs');
    }
};
