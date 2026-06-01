<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');                          // e.g. "Board Alpha — Downtown Core"
            $table->string('location')->nullable();          // physical location descriptor
            $table->string('geo_zone')->nullable();          // matches asset geo_campaign field
            $table->string('timezone')->default('UTC');
            $table->boolean('is_online')->default(false);
            $table->boolean('is_frozen')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->time('active_hours_start')->nullable();
            $table->time('active_hours_end')->nullable();
            $table->json('loop_orders')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
