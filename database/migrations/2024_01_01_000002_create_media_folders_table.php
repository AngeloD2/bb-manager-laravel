<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('parent_folder_id')->nullable();
            $table->boolean('is_fallback')->default(false);
            $table->unsignedInteger('max_daily_tokens')->nullable();    // folder-scoped daily cap
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_folder_id')
                  ->references('id')
                  ->on('media_folders')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};
