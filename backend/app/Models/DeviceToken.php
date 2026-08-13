<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Push targets for the Expo app. The device, not the login, owns the token:
 * a reinstall or an account switch reuses the same string, which is why the
 * hash carries the unique index and registration is an upsert.
 */
class DeviceToken extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token',
        'token_hash',
        'platform',
        'provider',
        'device_name',
        'app_version',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** sha256 so a 512-char token can carry a unique index (64 bytes). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Re-registers whatever the app sends on launch. Matching on token_hash
     * means a device that changes hands is reassigned, not duplicated, and
     * last_used_at doubles as the liveness signal the pruning job reads.
     */
    public static function registerFor(User $user, array $attributes): self
    {
        $token = (string) $attributes['token'];

        return static::updateOrCreate(
            ['token_hash' => static::hashToken($token)],
            [
                'user_id' => $user->getKey(),
                'token' => $token,
                'platform' => $attributes['platform'],
                'provider' => $attributes['provider'] ?? 'expo',
                'device_name' => $attributes['device_name'] ?? null,
                'app_version' => $attributes['app_version'] ?? null,
                'last_used_at' => now(),
            ],
        );
    }

    /** The hash is derived, never supplied -- the two cannot drift apart. */
    protected function token(): Attribute
    {
        return Attribute::set(fn (string $value): array => [
            'token' => $value,
            'token_hash' => static::hashToken($value),
        ]);
    }
}
