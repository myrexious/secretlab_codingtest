<?php

use App\Services\RecordStore;
use App\Support\RawJson;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/** Write a version and hand back its exact timestamp string, never a float. */
function writeVersion(string $key, mixed $value): string
{
    $envelope = app(RecordStore::class)->create($key, RawJson::encodeForStorage($value));

    preg_match('/"timestamp":([0-9.]+)}$/', $envelope, $matches);

    return $matches[1];
}

it('returns the newest version by default', function () {
    writeVersion('mykey', 'first');
    $second = writeVersion('mykey', 'second');

    getJson('/kv-value/data/mykey')
        ->assertOk()
        ->assertJson(['key' => 'mykey', 'value' => 'second', 'timestamp' => (float) $second]);
});

it('returns the value as it was at a past timestamp', function () {
    $first = writeVersion('mykey', 'first');
    writeVersion('mykey', 'second');

    getJson("/kv-value/data/mykey?timestamp={$first}")
        ->assertOk()
        ->assertJson(['value' => 'first']);
});

it('treats the as-of bound as inclusive', function () {
    $first = writeVersion('mykey', 'first');

    // Exactly ON the write's timestamp must find it.
    getJson("/kv-value/data/mykey?timestamp={$first}")->assertOk();
});

it('excludes a record written later in the same second', function () {
    // The reason the API exposes microseconds. Truncating to a whole second
    // would make this record unreachable at its own second boundary.
    $exact = writeVersion('mykey', 'first');
    $wholeSecond = (string) (int) $exact;

    expect($exact)->not->toBe($wholeSecond);

    getJson("/kv-value/data/mykey?timestamp={$wholeSecond}")->assertNotFound();
});

it('returns the latest version for a timestamp in the future', function () {
    writeVersion('mykey', 'first');
    writeVersion('mykey', 'second');

    getJson('/kv-value/data/mykey?timestamp=99999999999')
        ->assertOk()
        ->assertJson(['value' => 'second']);
});

describe('misses', function () {
    it('answers 404 for a key that was never written', function () {
        getJson('/kv-value/data/nosuchkey')
            ->assertNotFound()
            ->assertJsonFragment(['message' => 'No value has ever been stored for the key "nosuchkey".']);
    });

    it('answers 404 with a different message when the key exists but is too young', function () {
        writeVersion('mykey', 'first');

        expect(getJson('/kv-value/data/mykey?timestamp=1')->assertNotFound()->json('message'))
            ->toContain('exists, but no value was stored at or before');
    });

    it('never reports a miss as value null, because null is storable', function () {
        postJson('/kv-value/data', ['key' => 'realnull', 'value' => null])->assertCreated();

        // A stored null is a 200 with a null value.
        getJson('/kv-value/data/realnull')->assertOk()->assertJson(['value' => null]);

        // A miss is a 404, so the two can never be confused.
        getJson('/kv-value/data/missing')->assertNotFound();
    });
});

describe('routing edge cases', function () {
    it('serves a key literally named get-all-keys', function () {
        // The data/ prefix is what makes this work. Without it the literal
        // route would shadow the key and make it permanently unreadable.
        postJson('/kv-value/data', ['key' => 'get-all-keys', 'value' => 'i am a key'])->assertCreated();

        getJson('/kv-value/data/get-all-keys')->assertOk()->assertJson(['value' => 'i am a key']);
    });

    it('serves a key literally named bulk-create', function () {
        postJson('/kv-value/data', ['key' => 'bulk-create', 'value' => 'also a key'])->assertCreated();

        getJson('/kv-value/data/bulk-create')->assertOk()->assertJson(['value' => 'also a key']);
    });

    it('serves keys using every allowed punctuation character', function () {
        postJson('/kv-value/data', ['key' => 'a.b_c:d-e', 'value' => 1])->assertCreated();

        getJson('/kv-value/data/a.b_c:d-e')->assertOk();
    });
});

describe('timestamp validation', function () {
    it('rejects a non-numeric timestamp', function () {
        getJson('/kv-value/data/mykey?timestamp=yesterday')
            ->assertStatus(422)
            ->assertJsonValidationErrors('timestamp');
    });

    it('rejects a negative timestamp', function () {
        getJson('/kv-value/data/mykey?timestamp=-1')->assertStatus(422);
    });

    it('rejects scientific notation', function () {
        getJson('/kv-value/data/mykey?timestamp=1.7e9')->assertStatus(422);
    });

    it('accepts a whole number of seconds', function () {
        writeVersion('mykey', 'first');

        getJson('/kv-value/data/mykey?timestamp=99999999999')->assertOk();
    });
});
