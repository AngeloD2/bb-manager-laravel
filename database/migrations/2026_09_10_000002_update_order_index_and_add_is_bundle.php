<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->integer('order_index')->nullable()->default(null)->change();
        });

        Schema::table('media_loops', function (Blueprint $table) {
            $table->integer('order_index')->nullable()->default(null)->change();
            $table->boolean('is_bundle')->default(false)->after('is_global');
        });
    }

    public function down(): void
    {
        Schema::table('media_loops', function (Blueprint $table) {
            $table->dropColumn('is_bundle');
            $table->integer('order_index')->default(0)->nullable(false)->change();
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->integer('order_index')->default(0)->nullable(false)->change();
        });
    }
};
