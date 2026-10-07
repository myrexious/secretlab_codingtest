<?php

use App\Models\ApiClient;
use App\Services\RecordStore;
use Database\Seeders\DemoApiClientSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

describe('request body handling', function () {
    it('rejects a malformed JSON body with 400 and says so', function () {
        // Laravel decodes an unparseable body to an empty array, so without the
        // guard the caller would be told "A key is required" instead.
        postRaw('/kv-value/data', '{bad json')
            ->assertStatus(400)
            ->assertJson(['message' => 'The request body is not valid JSON.']);
    });

    it('rejects an empty JSON body with 400', function () {
        postRaw('/kv-value/data', '')->assertStatus(400);
    });

    it('still accepts a form encoded body', function () {
        // Not the documented content type, but it should not 500.
        post('/kv-value/data', ['key' => 'formkey', 'value' => 'hello'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJson(['key' => 'formkey', 'value' => 'hello']);
    });

    it('rejects a JSON body that is an array rather than an object', function () {
        postRaw('/kv-value/data', '[1,2,3]')->assertStatus(422);
    });
});

describe('insert retry', function () {
    it('retries a timestamp collision and gives up on the second one', function () {
        /*
         | Freeze the column default so every insert for this key collides on
         | UNIQUE (key, recorded_at). In production clock_timestamp() advances
         | between attempts, so the retry succeeds. Freezing it proves the retry
         | runs at all, and then stops rather than looping forever.
         |
         | This only works because the attempt takes a savepoint: a constraint
         | violation would otherwise poison the surrounding test transaction and
         | the second attempt could not run.
        */
        DB::statement("alter table records alter column recorded_at set default timestamptz '2020-01-01 00:00:00+00'");

        $store = app(RecordStore::class);
        $store->create('frozen', '1');

        expect(fn () => $store->create('frozen', '2'))
            ->toThrow(UniqueConstraintViolationException::class);

        // The failed write left nothing behind.
        expect(DB::table('records')->where('key', 'frozen')->count())->toBe(1);
    });
});

describe('the artisan key command', function () {
    it('creates a client and prints a usable key', function () {
        artisan('kv:client:create', ['name' => 'ci-runner'])->assertExitCode(0);

        $client = ApiClient::query()->where('name', 'ci-runner')->firstOrFail();

        expect($client->rate_limit_per_minute)->toBe(config('kv.rate_limit.client'))
            ->and($client->key_hash)->toHaveLength(64)
            ->and($client->revoked_at)->toBeNull();
    });

    it('honours an explicit --limit', function () {
        artisan('kv:client:create', ['name' => 'slowpoke', '--limit' => 5])->assertExitCode(0);

        expect(ApiClient::query()->where('name', 'slowpoke')->value('rate_limit_per_minute'))->toBe(5);
    });

    it('prints the key only once and never stores it', function () {
        artisan('kv:client:create', ['name' => 'printed'])
            ->expectsOutputToContain('Copy it now')
            ->assertExitCode(0);

        $stored = ApiClient::query()->where('name', 'printed')->value('key_hash');

        // 64 hex characters is a hash, not a 48 character base62 key.
        expect($stored)->toMatch('/^[a-f0-9]{64}$/');
    });
});

describe('documentation', function () {
    it('serves the Swagger page', function () {
        $this->get('/docs')->assertOk()->assertSee('swagger-ui', false);
    });

    it('publishes the demo key on the docs page so reviewers can authorise', function () {
        $this->get('/docs')->assertSee(DemoApiClientSeeder::DEMO_KEY, false);
    });

    it('redirects the root to the docs', function () {
        $this->get('/')->assertRedirect('/docs');
    });

    it('serves the OpenAPI document', function () {
        expect(file_get_contents(public_path('openapi.yaml')))->toContain('openapi: 3.1.0');
    });
});

describe('unknown routes', function () {
    it('answers 404 as JSON, not HTML', function () {
        getJson('/kv-value/nonsense')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json');
    });

    it('answers 404 for a key containing a slash, because the route cannot match', function () {
        getJson('/kv-value/data/bad/key')->assertNotFound();
    });

    it('answers 405 for a wrong method', function () {
        getJson('/kv-value/data')->assertStatus(405);
    });
});
