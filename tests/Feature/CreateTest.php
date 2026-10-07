<?php

use App\Services\RecordStore;
use App\Support\RawJson;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
| Fidelity is asserted against the RAW response body, never through assertJson().
| assertJson() decodes both sides, which would normalise away the exact thing
| under test. The inputs are raw JSON strings for the same reason: postJson()
| encodes a PHP array, and PHP's types cannot express the difference between
| {} and [], or between 1 and 1.0.
*/
dataset('values', [
    'integer' => ['42'],
    'negative integer' => ['-7'],
    'numeric string' => ['"42"'],
    'float' => ['3.5'],
    'float with a zero fraction' => ['1.0'],
    'boolean' => ['true'],
    'null' => ['null'],
    'empty array' => ['[]'],
    'empty object' => ['{}'],
    'object with numeric keys' => ['{"0":"a","1":"b"}'],
    'object key order' => ['{"b":1,"a":2}'],
    'nested' => ['{"z":[1,{"y":null}]}'],
    'unicode' => ['"cafe ☕"'],
    'forward slash' => ['"a/b"'],
    'array of objects' => ['[{"id":1},{"id":2}]'],
]);

it('returns a value byte-identical to what was sent', function (string $json) {
    $response = postRaw('/kv-value/data', '{"key":"fidelity","value":'.$json.'}');

    $response->assertCreated();
    expect($response->getContent())->toContain('"value":'.$json.',');
})->with('values');

it('round-trips every shape through a read as well as a write', function (string $json) {
    postRaw('/kv-value/data', '{"key":"roundtrip","value":'.$json.'}')->assertCreated();

    expect(getJson('/kv-value/data/roundtrip')->getContent())->toContain('"value":'.$json.',');
})->with('values');

it('preserves object key order rather than sorting it', function () {
    // This is the jsonb trap. A jsonb column would answer {"a":2,"b":1}.
    expect(postRaw('/kv-value/data', '{"key":"ordered","value":{"b":1,"a":2}}')->getContent())
        ->toContain('"value":{"b":1,"a":2},');
});

it('appends a new version instead of overwriting', function () {
    postJson('/kv-value/data', ['key' => 'versioned', 'value' => 'first'])->assertCreated();
    postJson('/kv-value/data', ['key' => 'versioned', 'value' => 'second'])->assertCreated();

    expect(DB::table('records')->where('key', 'versioned')->count())->toBe(2);
});

it('gives every version of one key a distinct timestamp', function () {
    // The unique index on (key, recorded_at) enforces this. Without it a history
    // listing could show two identical timestamps and no query could reach the
    // earlier record.
    $store = app(RecordStore::class);

    for ($i = 0; $i < 25; $i++) {
        $store->create('rapid', RawJson::encodeForStorage($i));
    }

    $timestamps = DB::table('records')->where('key', 'rapid')->pluck('recorded_at');

    expect($timestamps)->toHaveCount(25)
        ->and($timestamps->unique())->toHaveCount(25);
});

it('lets the database assign the timestamp, not the client', function () {
    $response = postJson('/kv-value/data', [
        'key' => 'nottheirs',
        'value' => 1,
        'timestamp' => 1,
    ]);

    expect(json_decode($response->getContent())->timestamp)->toBeGreaterThan(1_700_000_000);
});

describe('validation', function () {
    it('rejects a key containing a slash', function () {
        postJson('/kv-value/data', ['key' => 'bad/key', 'value' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');
    });

    it('rejects a key over 255 characters', function () {
        postJson('/kv-value/data', ['key' => str_repeat('a', 256), 'value' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');
    });

    it('accepts a key of exactly 255 characters', function () {
        postJson('/kv-value/data', ['key' => str_repeat('a', 255), 'value' => 1])
            ->assertCreated();
    });

    it('rejects an absent value but accepts an explicit null', function () {
        postJson('/kv-value/data', ['key' => 'novalue'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');

        postJson('/kv-value/data', ['key' => 'nullvalue', 'value' => null])
            ->assertCreated();
    });

    it('rejects a value over 256 KiB', function () {
        postJson('/kv-value/data', ['key' => 'toobig', 'value' => str_repeat('x', 256 * 1024)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    });

    it('accepts a value just under the limit', function () {
        // 256 KiB minus the two quote characters the encoding adds.
        postJson('/kv-value/data', ['key' => 'justright', 'value' => str_repeat('x', 256 * 1024 - 2)])
            ->assertCreated();
    });

    it('names the offending value in the message', function () {
        $response = postJson('/kv-value/data', ['key' => 'bad/key', 'value' => 1]);

        expect($response->json('errors.key.0'))->toContain('bad/key');
    });
});

describe('bulk create', function () {
    it('stores every pair', function () {
        postJson('/kv-value/bulk-create', ['pairs' => [
            ['key' => 'b.one', 'value' => 1],
            ['key' => 'b.two', 'value' => ['x' => 2]],
        ]])->assertCreated()->assertJsonCount(2, 'data');

        expect(DB::table('records')->count())->toBe(2);
    });

    it('keeps the JSON shape of every pair', function () {
        $body = '{"pairs":[{"key":"s.one","value":{}},{"key":"s.two","value":1.0}]}';

        expect(postRaw('/kv-value/bulk-create', $body)->getContent())
            ->toContain('"value":{},')
            ->toContain('"value":1.0,');
    });

    it('writes nothing when one pair is invalid', function () {
        postJson('/kv-value/bulk-create', ['pairs' => [
            ['key' => 'good', 'value' => 1],
            ['key' => 'bad/key', 'value' => 2],
        ]])->assertStatus(422);

        expect(DB::table('records')->count())->toBe(0);
    });

    it('rejects the same key twice in one request', function () {
        // Two records for one key would need two timestamps, and which counted
        // as "latest" would come down to row order.
        postJson('/kv-value/bulk-create', ['pairs' => [
            ['key' => 'dup', 'value' => 1],
            ['key' => 'dup', 'value' => 2],
        ]])->assertStatus(422);

        expect(DB::table('records')->count())->toBe(0);
    });

    it('rejects more than 50 pairs', function () {
        $pairs = array_map(fn (int $i) => ['key' => "k{$i}", 'value' => $i], range(1, 51));

        postJson('/kv-value/bulk-create', ['pairs' => $pairs])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pairs');
    });

    it('accepts exactly 50 pairs', function () {
        $pairs = array_map(fn (int $i) => ['key' => "k{$i}", 'value' => $i], range(1, 50));

        postJson('/kv-value/bulk-create', ['pairs' => $pairs])->assertCreated();
    });

    it('rejects an empty pairs array', function () {
        postJson('/kv-value/bulk-create', ['pairs' => []])->assertStatus(422);
    });
});
