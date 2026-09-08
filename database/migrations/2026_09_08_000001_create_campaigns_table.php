<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Campaign is an advertiser's booking: a named period that owns the Loops
 * running during it. Previously this existed only as a free-text
 * `campaign_name` denormalised onto every asset. See CONTEXT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('media_loops', function (Blueprint $table) {
            // Nullable: a loop with no campaign is unsold or perpetual
            // inventory, which is exactly what a Fallback loop is. No sentinel
            // "Unsold" campaign row.
            $table->foreignUuid('campaign_id')->nullable()->after('name')
                  ->constrained('campaigns')->nullOnDelete();
        });

        // Grouping loops is what a Campaign is for. The nesting this implied
        // was never real: nothing server-side ever read it and no client set it.
        Schema::table('media_loops', function (Blueprint $table) {
            $table->dropForeign(['parent_loop_id']);
            $table->dropColumn('parent_loop_id');
        });

        Schema::table('media_assets', function (Blueprint $table) {
            // The campaign's name, one level up.
            $table->dropColumn('campaign_name');
            // These stay, but as an optional narrowing override on top of the
            // campaign's window — so they get names that say so.
            $table->renameColumn('campaign_start_date', 'runs_from');
            $table->renameColumn('campaign_end_date', 'runs_until');
        });

        // Now that Campaign is a real entity, "sold to a campaign" can be a join.
        Schema::table('fallback_spot_records', function (Blueprint $table) {
            $table->dropColumn('campaign_id');
        });
        Schema::table('fallback_spot_records', function (Blueprint $table) {
            $table->foreignUuid('campaign_id')->nullable()
                  ->constrained('campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fallback_spot_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
        Schema::table('fallback_spot_records', function (Blueprint $table) {
            $table->string('campaign_id')->nullable();
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->renameColumn('runs_until', 'campaign_end_date');
            $table->renameColumn('runs_from', 'campaign_start_date');
            $table->string('campaign_name')->nullable();
        });

        Schema::table('media_loops', function (Blueprint $table) {
            $table->uuid('parent_loop_id')->nullable();
            $table->foreign('parent_loop_id')->references('id')->on('media_loops')->nullOnDelete();
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::dropIfExists('campaigns');
    }
};
