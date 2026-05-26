<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stores pending override commands pushed from the admin app to a billboard device.
        // The billboard polls GET /api/v1/sync and consumes + deletes these.
        Schema::create('timeline_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->uuid('device_id');
            $table->boolean('consumed')->default(false);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('media_assets')->cascadeOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_overrides');
    }
};
