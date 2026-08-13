<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed key/value site config. The `value` column is TEXT for every type;
 * `type` says how to read it back, so there is no Eloquent cast to hang on it.
 */
class Setting extends Model
{
    use HasFactory;

    public const PUBLIC_CACHE_KEY = 'settings.public';

    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'is_public',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /** Any write invalidates the public map, including admin-panel saves. */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::PUBLIC_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::PUBLIC_CACHE_KEY));
    }

    public function scopePublic(Builder $query): void
    {
        $query->where('is_public', true);
    }

    /** Decode `value` according to `type`. */
    public function typedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'int' => (int) $this->value,
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            'encrypted' => Crypt::decryptString($this->value),
            default => (string) $this->value,
        };
    }

    /**
     * Reads as Setting::get('contact', 'phone'). This deliberately shadows the
     * static forward to Builder::get() — fetch rows via Setting::query()->get().
     */
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('group', $group)->where('key', $key)->first();

        return $setting?->typedValue() ?? $default;
    }

    public static function putValue(string $group, string $key, mixed $value, string $type = 'string'): void
    {
        static::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => static::encode($value, $type), 'type' => $type],
        );

        Cache::forget(self::PUBLIC_CACHE_KEY);
    }

    /**
     * Feeds GET /settings/public. Cached forever because settings change from
     * the admin panel only, and every write forgets the key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function publicMap(): array
    {
        return Cache::rememberForever(self::PUBLIC_CACHE_KEY, function (): array {
            $map = [];

            foreach (static::query()->public()->get() as $setting) {
                // A secret marked is_public is a misconfiguration, not an intent
                // to publish it; refuse to decrypt into an unauthenticated response.
                if ($setting->type === 'encrypted') {
                    continue;
                }

                $map[$setting->group][$setting->key] = $setting->typedValue();
            }

            return $map;
        });
    }

    protected static function encode(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (string) (int) $value,
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value),
            'encrypted' => Crypt::encryptString((string) $value),
            default => (string) $value,
        };
    }
}
