<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\HasApiTokens;

class Device extends Model
{
    use HasUuids, HasApiTokens;

    protected $fillable = [
        'name',
        'location',
        'geo_zone',
        'timezone',
        'is_online',
        'is_frozen',
        'last_seen_at',
        'active_hours_start',
        'active_hours_end',
        'loop_orders',
    ];

    protected $casts = [
        'is_online'    => 'boolean',
        'is_frozen'    => 'boolean',
        'last_seen_at' => 'datetime',
        'loop_orders'  => 'array',
    ];

    protected $attributes = [
        'timezone' => 'UTC',
    ];

    // password / password_fingerprint are intentionally not fillable; set via setPassword().
    protected $hidden = [
        'password',
        'password_fingerprint',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function playbackLogs(): HasMany
    {
        return $this->hasMany(PlaybackLog::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(TimelineOverride::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Mark this device as recently seen and online. */
    public function heartbeat(): void
    {
        $this->update([
            'is_online'    => true,
            'last_seen_at' => now(),
        ]);
    }

    /** Unconsumed override commands waiting for this board. */
    public function pendingOverrides(): HasMany
    {
        return $this->overrides()->where('consumed', false)->orderBy('created_at');
    }

    /** Set the device's timezone, falling back to application timezone or UTC if null. */
    public function setTimezoneAttribute($value): void
    {
        $this->attributes['timezone'] = $value ?? config('app.timezone', 'UTC');
    }

    // ── Player password ────────────────────────────────────────────────────────

    /** Store the player password: encrypted for reveal, fingerprinted for lookup/uniqueness. */
    public function setPassword(string $plain): void
    {
        $this->password = Crypt::encryptString($plain);
        $this->password_fingerprint = static::fingerprint($plain);
    }

    /** The plaintext player password, decrypted for display to the admin. */
    public function getPlainPasswordAttribute(): ?string
    {
        return $this->password ? Crypt::decryptString($this->password) : null;
    }

    /** Keyed deterministic hash used for the unique index and login lookups. */
    public static function fingerprint(string $plain): string
    {
        return hash_hmac('sha256', $plain, config('app.key'));
    }

    /** A short, human-typeable password like "blue-tiger-42". */
    public static function generatePassword(): string
    {
        $adjectives = ['blue', 'red', 'gold', 'green', 'swift', 'bright', 'calm', 'bold', 'quiet', 'sharp'];
        $nouns = ['tiger', 'river', 'maple', 'comet', 'falcon', 'harbor', 'ember', 'willow', 'summit', 'orbit'];

        return $adjectives[array_rand($adjectives)]
            . '-' . $nouns[array_rand($nouns)]
            . '-' . random_int(10, 99);
    }
}
