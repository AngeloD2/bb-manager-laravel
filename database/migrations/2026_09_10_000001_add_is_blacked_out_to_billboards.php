<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blacking out is a second kind of hold, alongside freezing.
 *
 * Both stop the loop; they differ in what the panel shows and in what resuming
 * does. A frozen board keeps its current frame and resumes mid-asset; a blacked
 * out board shows nothing and replays that asset from the start. Keeping it as
 * its own flag rather than overloading is_frozen means the existing freeze
 * behaviour, and everything already asserting on it, is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billboards', function (Blueprint $table) {
            $table->boolean('is_blacked_out')->default(false)->after('is_frozen');
        });
    }

    public function down(): void
    {
        Schema::table('billboards', function (Blueprint $table) {
            $table->dropColumn('is_blacked_out');
        });
    }
};
