<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key for offline-first playback logging.
 *
 * The billboard player now counts spots locally and flushes play events when it
 * regains connectivity. A flaky link can cause the same event to be POSTed more
 * than once, so each event carries a client-generated UUID; the unique
 * (device_id, client_event_id) index lets the server dedup and charge a spot
 * exactly once. Nullable so historical rows and any legacy non-keyed writes
 * remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playback_logs', function (Blueprint $table) {
            $table->uuid('client_event_id')->nullable()->after('device_id');
            $table->unique(['device_id', 'client_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('playback_logs', function (Blueprint $table) {
            $table->dropUnique(['device_id', 'client_event_id']);
            $table->dropColumn('client_event_id');
        });
    }
};
