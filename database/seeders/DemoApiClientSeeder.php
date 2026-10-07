<?php

namespace Database\Seeders;

use App\Models\ApiClient;
use Illuminate\Database\Seeder;

/**
 * One API key with a FIXED, published value.
 *
 * This is deliberate, not an oversight. The repository is public and the
 * reviewers need the Swagger page to work on first click. The key carries no
 * privilege beyond a rate-limit bucket, and its limit is lower than a private
 * key's precisely because the whole internet can read it.
 *
 * Revoke it with:
 *   update api_clients set revoked_at = now() where name = 'Public demo key';
 */
class DemoApiClientSeeder extends Seeder
{
    public const DEMO_KEY = 'demo-secretlab-kv-store-public-readonly-key';

    public function run(): void
    {
        ApiClient::query()->updateOrCreate(
            ['key_hash' => ApiClient::hash(self::DEMO_KEY)],
            [
                'name' => 'Public demo key',
                'rate_limit_per_minute' => config('kv.rate_limit.demo'),
            ],
        );
    }
}
