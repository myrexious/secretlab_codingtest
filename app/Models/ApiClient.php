<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A caller identified by an API key. The rate limiter counts per client.
 *
 * Eloquent is fine here. The fidelity rule that keeps RecordStore on raw SQL
 * does not apply: nothing on this table is a caller-supplied JSON document.
 */
class ApiClient extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['name', 'key_hash', 'rate_limit_per_minute'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    /** 48 characters of base62 is about 285 bits, well past brute force. */
    public static function generateKey(): string
    {
        return Str::random(48);
    }

    public static function hash(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /** Returns null for an unknown or revoked key. */
    public static function findByKey(string $plainKey): ?self
    {
        return static::query()
            ->whereNull('revoked_at')
            ->where('key_hash', self::hash($plainKey))
            ->first();
    }
}
