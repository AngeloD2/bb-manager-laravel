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
        Schema::create('queue_rejection_stats', function (Blueprint $table) {
            $table->uuid('billboard_id')->index();
            $table->uuid('asset_id')->index();
            $table->string('reason');
            $table->date('date');
            $table->integer('count')->default(1);
            $table->unique(['billboard_id', 'asset_id', 'reason', 'date'], 'q_rej_stats_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_rejection_stats');
    }
};
