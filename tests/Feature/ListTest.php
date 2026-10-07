<?php

use App\Services\RecordStore;
use App\Support\Cursor;
use App\Support\RawJson;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function write(string $key, mixed $value): void
{
    app(RecordStore::class)->create($key, RawJson::encodeForStorage($value));
}

describe('history', function () {
    it('lists every version of one key, newest first', function () {
        write('mykey', 'v1');
        write('mykey', 'v2');
        write('mykey', 'v3');
        write('other', 'not mine');

        $response = getJson('/kv-value/history/mykey')->assertOk();

        expect($response->json('data.*.value'))->toBe(['v3', 'v2', 'v1'])
            ->and($response->json('next_cursor'))->toBeNull();
    });

    it('pages through history with a cursor', function () {
        foreach (range(1, 5) as $i) {
            write('mykey', "v{$i}");
        }

        $page1 = getJson('/kv-value/history/mykey?limit=2')->assertOk();
        expect($page1->json('data.*.value'))->toBe(['v5', 'v4'])
            ->and($page1->json('next_cursor'))->not->toBeNull();

        $page2 = getJson('/kv-value/history/mykey?limit=2&cursor='.$page1->json('next_cursor'))->assertOk();
        expect($page2->json('data.*.value'))->toBe(['v3', 'v2']);

        $page3 = getJson('/kv-value/history/mykey?limit=2&cursor='.$page2->json('next_cursor'))->assertOk();
        expect($page3->json('data.*.value'))->toBe(['v1'])
            ->and($page3->json('next_cursor'))->toBeNull();
    });

    it('answers 404 for a key that was never written', function () {
        getJson('/kv-value/history/nosuchkey')->assertNotFound();
    });

    it('answers 200 with an empty page when the caller walks off the end', function () {
        write('mykey', 'only');

        $cursor = Cursor::encode('1');

        getJson("/kv-value/history/mykey?cursor={$cursor}")
            ->assertOk()
            ->assertJson(['data' => [], 'next_cursor' => null]);
    });
});

describe('get-all-keys', function () {
    it('returns one entry per key holding its latest value', function () {
        write('alpha', 'old');
        write('alpha', 'new');
        write('beta', 1);

        $response = getJson('/kv-value/get-all-keys')->assertOk();

        expect($response->json('data.*.key'))->toBe(['alpha', 'beta'])
            ->and($response->json('data.*.value'))->toBe(['new', 1]);
    });

    it('orders keys alphabetically and pages with a cursor', function () {
        foreach (['e', 'a', 'd', 'b', 'c'] as $key) {
            write($key, $key);
        }

        $page1 = getJson('/kv-value/get-all-keys?limit=2')->assertOk();
        expect($page1->json('data.*.key'))->toBe(['a', 'b']);

        $page2 = getJson('/kv-value/get-all-keys?limit=2&cursor='.$page1->json('next_cursor'))->assertOk();
        expect($page2->json('data.*.key'))->toBe(['c', 'd']);

        $page3 = getJson('/kv-value/get-all-keys?limit=2&cursor='.$page2->json('next_cursor'))->assertOk();
        expect($page3->json('data.*.key'))->toBe(['e'])
            ->and($page3->json('next_cursor'))->toBeNull();
    });

    it('returns an empty page rather than 404 when nothing is stored', function () {
        getJson('/kv-value/get-all-keys')
            ->assertOk()
            ->assertJson(['data' => [], 'next_cursor' => null]);
    });

    it('defaults to a page size of 50', function () {
        postJson('/kv-value/bulk-create', ['pairs' => array_map(
            fn (int $i) => ['key' => sprintf('k%03d', $i), 'value' => $i],
            range(1, 50),
        )])->assertCreated();

        postJson('/kv-value/bulk-create', ['pairs' => array_map(
            fn (int $i) => ['key' => sprintf('k%03d', $i), 'value' => $i],
            range(51, 60),
        )])->assertCreated();

        $response = getJson('/kv-value/get-all-keys')->assertOk();

        expect($response->json('data'))->toHaveCount(50)
            ->and($response->json('next_cursor'))->not->toBeNull();
    });
});

describe('pagination guards', function () {
    it('rejects a limit above the maximum', function () {
        getJson('/kv-value/get-all-keys?limit=201')
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');
    });

    it('accepts a limit of exactly the maximum', function () {
        getJson('/kv-value/get-all-keys?limit=200')->assertOk();
    });

    it('rejects a limit below one', function () {
        getJson('/kv-value/get-all-keys?limit=0')->assertStatus(422);
    });

    it('rejects a non-numeric limit', function () {
        getJson('/kv-value/get-all-keys?limit=lots')->assertStatus(422);
    });

    it('rejects a malformed cursor with 422 rather than a 500', function () {
        // Without the decoded-cursor check, this string would reach PostgreSQL
        // and blow up as a cast error.
        write('mykey', 1);

        getJson('/kv-value/history/mykey?cursor=notarealcursor')
            ->assertStatus(422)
            ->assertJsonValidationErrors('cursor');
    });

    it('rejects a cursor from the wrong endpoint', function () {
        write('mykey', 1);

        // A get-all-keys cursor holds a key, not an epoch, so history must
        // refuse it instead of passing a non-numeric string to the database.
        getJson('/kv-value/history/mykey?cursor='.Cursor::encode('somekey'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cursor');
    });

    it('rejects a cursor carrying a SQL fragment', function () {
        write('mykey', 1);

        getJson('/kv-value/history/mykey?cursor='.Cursor::encode('1); drop table records; --'))
            ->assertStatus(422);

        expect(getJson('/kv-value/data/mykey')->assertOk())->not->toBeNull();
    });
});
