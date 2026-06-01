<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fallback_spot_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('media_loops')->cascadeOnDelete();
            $table->date('spot_date');
            $table->string('status')->default('available'); // 'available' or 'sold'
            $table->string('campaign_id')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'loop_id', 'spot_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fallback_spot_records');
    }
};
