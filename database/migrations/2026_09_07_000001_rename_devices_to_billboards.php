<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * "Billboard" is the domain term for one physical advertising display; "device"
 * was a legacy name that only ever survived in this repo. See CONTEXT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('devices', 'billboards');

        foreach (['playback_logs', 'timeline_overrides', 'fallback_spot_records'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('device_id', 'billboard_id'));
        }

        foreach (['media_loops', 'media_assets'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('assigned_devices', 'assigned_billboards'));
        }

        // Sanctum stores the tokenable's FQCN as a string, so every token issued
        // to a board would stop resolving without this.
        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\Device')
            ->update(['tokenable_type' => 'App\\Models\\Billboard']);
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\Billboard')
            ->update(['tokenable_type' => 'App\\Models\\Device']);

        foreach (['media_loops', 'media_assets'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('assigned_billboards', 'assigned_devices'));
        }

        foreach (['playback_logs', 'timeline_overrides', 'fallback_spot_records'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('billboard_id', 'device_id'));
        }

        Schema::rename('billboards', 'devices');
    }
};
