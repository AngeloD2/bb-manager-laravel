<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Encrypted (reversible) so an admin can reveal it to type into a player.
            $table->text('password')->nullable()->after('name');
            // Keyed deterministic hash: unique index guarantees no two boards share a
            // password, and lets device login look a board up by password alone.
            $table->string('password_fingerprint')->nullable()->unique()->after('password');
        });

        // Backfill existing boards with generated unique passwords.
        $key = config('app.key');
        $used = [];
        foreach (DB::table('devices')->get() as $device) {
            do {
                $plain = self::generatePassword();
                $fingerprint = hash_hmac('sha256', $plain, $key);
            } while (isset($used[$fingerprint]));
            $used[$fingerprint] = true;

            DB::table('devices')->where('id', $device->id)->update([
                'password'             => Crypt::encryptString($plain),
                'password_fingerprint' => $fingerprint,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['password_fingerprint']);
            $table->dropColumn(['password', 'password_fingerprint']);
        });
    }

    private static function generatePassword(): string
    {
        $adjectives = ['blue', 'red', 'gold', 'green', 'swift', 'bright', 'calm', 'bold', 'quiet', 'sharp'];
        $nouns = ['tiger', 'river', 'maple', 'comet', 'falcon', 'harbor', 'ember', 'willow', 'summit', 'orbit'];

        return $adjectives[array_rand($adjectives)]
            . '-' . $nouns[array_rand($nouns)]
            . '-' . random_int(10, 99);
    }
};
