<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('label');
            $table->uuid('loop_id')->nullable();
            $table->uuid('asset_id')->nullable();
            $table->string('token', 8)->unique();           // short URL token, e.g. abc12def
            $table->string('password_hash');                 // bcrypt hash of the 6-digit PIN
            $table->timestamp('expires_at');
            $table->boolean('is_one_time')->default(false);
            $table->boolean('is_expired')->default(false);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();

            $table->foreign('loop_id')->references('id')->on('media_loops')->nullOnDelete();
            $table->foreign('asset_id')->references('id')->on('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_share_links');
    }
};
