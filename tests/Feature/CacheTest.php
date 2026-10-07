<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Insert a row straight into PostgreSQL, behind the API and behind the cache.
 * This is how the tests tell a cache hit apart from a real read.
 */
function insertBehindTheCache(string $key, string $rawJson): void
{
    DB::insert('insert into records (key, value) values (?, ?::json)', [$key, $rawJson]);
}

it('caches the latest read', function () {
    postJson('/kv-value/data', ['key' => 'cached', 'value' => 'ORIGINAL'])->assertCreated();

    getJson('/kv-value/data/cached')->assertOk()->assertJson(['value' => 'ORIGINAL']);

    insertBehindTheCache('cached', '"CHANGED"');

    // Still the cached answer, which is the proof that a cache exists at all.
    getJson('/kv-value/data/cached')->assertOk()->assertJson(['value' => 'ORIGINAL']);
});

it('bypasses the cache when direct=true', function () {
    postJson('/kv-value/data', ['key' => 'cached', 'value' => 'ORIGINAL'])->assertCreated();
    getJson('/kv-value/data/cached')->assertOk();

    insertBehindTheCache('cached', '"CHANGED"');

    getJson('/kv-value/data/cached?direct=true')->assertOk()->assertJson(['value' => 'CHANGED']);
});

it('leaves the cache populated after a direct read', function () {
    postJson('/kv-value/data', ['key' => 'cached', 'value' => 'ORIGINAL'])->assertCreated();
    getJson('/kv-value/data/cached')->assertOk();

    insertBehindTheCache('cached', '"CHANGED"');

    getJson('/kv-value/data/cached?direct=true')->assertJson(['value' => 'CHANGED']);

    // A bypass reads around the cache; it does not refresh it.
    getJson('/kv-value/data/cached')->assertJson(['value' => 'ORIGINAL']);
});

it('evicts the cached entry when a write goes through the API', function () {
    postJson('/kv-value/data', ['key' => 'cached', 'value' => 'ORIGINAL'])->assertCreated();
    getJson('/kv-value/data/cached')->assertOk();

    postJson('/kv-value/data', ['key' => 'cached', 'value' => 'SECOND'])->assertCreated();

    getJson('/kv-value/data/cached')->assertOk()->assertJson(['value' => 'SECOND']);
});

it('evicts every key touched by a bulk write', function () {
    postJson('/kv-value/bulk-create', ['pairs' => [
        ['key' => 'a', 'value' => 'first'],
        ['key' => 'b', 'value' => 'first'],
    ]])->assertCreated();

    getJson('/kv-value/data/a')->assertJson(['value' => 'first']);
    getJson('/kv-value/data/b')->assertJson(['value' => 'first']);

    postJson('/kv-value/bulk-create', ['pairs' => [
        ['key' => 'a', 'value' => 'second'],
        ['key' => 'b', 'value' => 'second'],
    ]])->assertCreated();

    getJson('/kv-value/data/a')->assertJson(['value' => 'second']);
    getJson('/kv-value/data/b')->assertJson(['value' => 'second']);
});

it('does not cache an as-of read', function () {
    // A past value is immutable, so a cache entry could never be reused before
    // it expired. Caching it would only grow the cache.
    postJson('/kv-value/data', ['key' => 'cached', 'value' => 'ORIGINAL'])->assertCreated();

    getJson('/kv-value/data/cached?timestamp=99999999999')->assertJson(['value' => 'ORIGINAL']);

    insertBehindTheCache('cached', '"CHANGED"');

    getJson('/kv-value/data/cached?timestamp=99999999999')->assertJson(['value' => 'CHANGED']);
});

it('does not cache a miss, so a key written later is seen at once', function () {
    getJson('/kv-value/data/later')->assertNotFound();

    insertBehindTheCache('later', '"NOW I EXIST"');

    getJson('/kv-value/data/later')->assertOk()->assertJson(['value' => 'NOW I EXIST']);
});

it('keeps one key cache separate from another', function () {
    postJson('/kv-value/data', ['key' => 'a', 'value' => 'A'])->assertCreated();
    postJson('/kv-value/data', ['key' => 'b', 'value' => 'B'])->assertCreated();

    getJson('/kv-value/data/a')->assertJson(['value' => 'A']);
    getJson('/kv-value/data/b')->assertJson(['value' => 'B']);

    postJson('/kv-value/data', ['key' => 'a', 'value' => 'A2'])->assertCreated();

    getJson('/kv-value/data/a')->assertJson(['value' => 'A2']);
    getJson('/kv-value/data/b')->assertJson(['value' => 'B']);
});

it('rejects a direct flag that is not a boolean', function () {
    getJson('/kv-value/data/anything?direct=maybe')
        ->assertStatus(422)
        ->assertJsonValidationErrors('direct');
});
