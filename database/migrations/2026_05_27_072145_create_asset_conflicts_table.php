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
        Schema::create('asset_conflicts', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id_1');
            $table->uuid('asset_id_2');
            $table->timestamps();

            $table->foreign('asset_id_1')->references('id')->on('media_assets')->onDelete('cascade');
            $table->foreign('asset_id_2')->references('id')->on('media_assets')->onDelete('cascade');

            $table->unique(['asset_id_1', 'asset_id_2']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_conflicts');
    }
};
