<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });
        
        Schema::table('billboards', function (Blueprint $table) {
            $table->uuid('zone_id')->nullable();
            $table->foreign('zone_id')->references('id')->on('zones')->nullOnDelete();
            $table->dropColumn('geo_zone');
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->json('targeted_zones')->nullable();
            $table->dropColumn('geo_campaign');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('geo_campaign')->nullable();
            $table->dropColumn('targeted_zones');
        });

        Schema::table('billboards', function (Blueprint $table) {
            $table->string('geo_zone')->nullable();
            $table->dropForeign(['zone_id']);
            $table->dropColumn('zone_id');
        });

        Schema::dropIfExists('zones');
    }
};
