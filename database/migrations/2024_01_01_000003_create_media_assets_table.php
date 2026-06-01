<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('file_path');                                         // S3 object key
            $table->enum('file_type', ['VIDEO', 'GIF', 'PHOTO']);
            $table->uuid('loop_id')->nullable();
            $table->integer('order_index')->default(0);                          // order within loop
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedSmallInteger('duration_secs')->default(10);          // 8–15 second display window
            $table->string('geo_campaign')->nullable();
            $table->string('campaign_name')->nullable();
            $table->date('campaign_start_date')->nullable();
            $table->date('campaign_end_date')->nullable();
            $table->json('playback_times')->nullable();
            $table->boolean('is_synced')->default(false);                        // true once S3 + FFmpeg done
            $table->boolean('is_global')->default(false);
            $table->unsignedSmallInteger('max_plays_per_hour')->nullable();       // micro file-level override
            $table->unsignedSmallInteger('max_daily_plays')->nullable();          // micro file-level override
            $table->unsignedInteger('play_spots_remaining')->default(100);       // 1 play = 1 spot
            $table->json('assigned_devices')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('loop_id')
                  ->references('id')
                  ->on('media_loops')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
