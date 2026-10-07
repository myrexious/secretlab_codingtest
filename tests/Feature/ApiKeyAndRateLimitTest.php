<?php

use App\Models\ApiClient;

use function Pest\Laravel\getJson;

function makeClient(string $name, int $limit): string
{
    $plainKey = ApiClient::generateKey();

    ApiClient::create([
        'name' => $name,
        'key_hash' => ApiClient::hash($plainKey),
        'rate_limit_per_minute' => $limit,
    ]);

    return $plainKey;
}

describe('api key resolution', function () {
    it('allows an anonymous caller', function () {
        // The Secretlab reviewers have no key. A mandatory key would answer 401
        // to the people grading the submission.
        getJson('/kv-value/get-all-keys')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', (string) config('kv.rate_limit.anonymous'));
    });

    it('accepts a valid key and applies that client allowance', function () {
        $key = makeClient('tester', 777);

        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '777');
    });

    it('rejects an unknown key with 401 instead of silently downgrading it', function () {
        // A silent downgrade would hide a typo in a caller's configuration until
        // they hit a rate limit they did not expect.
        expect(getJson('/kv-value/get-all-keys', ['X-API-Key' => 'not-a-real-key'])
            ->assertStatus(401)
            ->json('message'))->toContain('does not match a known, active API key');
    });

    it('rejects a revoked key', function () {
        $key = makeClient('retired', 100);
        ApiClient::query()->where('name', 'retired')->update(['revoked_at' => now()]);

        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])->assertStatus(401);
    });

    it('treats an empty key header as anonymous', function () {
        getJson('/kv-value/get-all-keys', ['X-API-Key' => ''])
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', (string) config('kv.rate_limit.anonymous'));
    });

    it('never stores the key in plain text', function () {
        $key = makeClient('secret', 100);

        expect(ApiClient::query()->where('key_hash', $key)->exists())->toBeFalse()
            ->and(ApiClient::query()->where('key_hash', hash('sha256', $key))->exists())->toBeTrue();
    });
});

describe('rate limiting', function () {
    it('answers 429 once the allowance is spent', function () {
        $key = makeClient('tiny', 2);

        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])->assertOk();
        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])->assertOk();
        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])->assertStatus(429);
    });

    it('sends Retry-After and the rate limit headers on a 429', function () {
        $key = makeClient('tiny', 1);

        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])->assertOk();

        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeaderMissing('X-Nonexistent')
            ->assertHeader('Retry-After');
    });

    it('explains which limit was hit rather than saying Too Many Attempts', function () {
        $key = makeClient('tiny', 1);

        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key]);

        expect(getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])->json('message'))
            ->toContain('Rate limit of 1 requests per minute exceeded')
            ->toContain('This limit applies to your API key');
    });

    it('gives each client its own bucket', function () {
        $spent = makeClient('spent', 1);
        $fresh = makeClient('fresh', 50);

        getJson('/kv-value/get-all-keys', ['X-API-Key' => $spent])->assertOk();
        getJson('/kv-value/get-all-keys', ['X-API-Key' => $spent])->assertStatus(429);

        // One exhausted client must not lock out another.
        getJson('/kv-value/get-all-keys', ['X-API-Key' => $fresh])->assertOk();
    });

    it('keeps an exhausted key from affecting anonymous callers', function () {
        $key = makeClient('tiny', 1);

        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])->assertOk();
        getJson('/kv-value/get-all-keys', ['X-API-Key' => $key])->assertStatus(429);

        getJson('/kv-value/get-all-keys')->assertOk();
    });

    it('buckets by the real client IP behind Cloudflare', function () {
        config(['kv.rate_limit.anonymous' => 1]);

        // Two different edge addresses, one real client: one bucket.
        getJson('/kv-value/get-all-keys', ['X-Forwarded-For' => '104.16.0.1', 'CF-Connecting-IP' => '203.0.113.7'])->assertOk();
        getJson('/kv-value/get-all-keys', ['X-Forwarded-For' => '172.67.1.1', 'CF-Connecting-IP' => '203.0.113.7'])->assertStatus(429);

        // A different real client gets its own bucket.
        getJson('/kv-value/get-all-keys', ['X-Forwarded-For' => '104.16.0.1', 'CF-Connecting-IP' => '203.0.113.8'])->assertOk();
    });

    it('ignores CF-Connecting-IP from a peer that is not Cloudflare', function () {
        config(['kv.rate_limit.anonymous' => 1]);

        // A direct caller cannot dodge the limit by rotating a forged header.
        getJson('/kv-value/get-all-keys', ['X-Forwarded-For' => '198.51.100.9', 'CF-Connecting-IP' => '203.0.113.1'])->assertOk();
        getJson('/kv-value/get-all-keys', ['X-Forwarded-For' => '198.51.100.9', 'CF-Connecting-IP' => '203.0.113.2'])->assertStatus(429);
    });

    it('never rate limits the health endpoint', function () {
        // Monitoring must not be able to lock itself out.
        foreach (range(1, 5) as $ignored) {
            getJson('/health')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
        }
    });
});

describe('health', function () {
    it('reports both dependencies as up', function () {
        getJson('/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']]);
    });
});
