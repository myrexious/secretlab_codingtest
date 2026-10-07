<?php

use App\Models\ApiClient;
use App\Support\Cursor;
use App\Support\RawJson;

describe('Cursor', function () {
    it('round-trips a value', function (string $value) {
        expect(Cursor::decode(Cursor::encode($value)))->toBe($value);
    })->with([
        'an epoch' => ['1440568980.123456'],
        'a key' => ['some.key:name-here'],
        'one character' => ['a'],
        'a long key' => [str_repeat('k', 255)],
        'punctuation' => ['a.b_c:d-e'],
    ]);

    it('produces URL-safe output with no padding', function () {
        // base64url, so a cursor survives a query string untouched and does not
        // invite callers to build one by hand.
        foreach (['a', 'ab', 'abc', 'abcd', '1440568980.123456'] as $value) {
            expect(Cursor::encode($value))->toMatch('/^[A-Za-z0-9_-]+$/');
        }
    });

    it('returns null for input that is not base64', function () {
        expect(Cursor::decode('not valid base64 !!!'))->toBeNull();
    });

    it('never emits characters that need percent-encoding', function () {
        $encoded = Cursor::encode(str_repeat("\xff\xfe\xfd", 8));

        expect($encoded)->toBe(rawurlencode($encoded));
    });
});

describe('RawJson', function () {
    it('splices the stored value in without re-encoding it', function () {
        // The whole point: the value text goes through untouched, so key order
        // and spacing survive.
        expect(RawJson::envelope('k', '{"b":1,  "a":2}', '1.5'))
            ->toBe('{"key":"k","value":{"b":1,  "a":2},"timestamp":1.5}');
    });

    it('escapes the key but never the value', function () {
        expect(RawJson::envelope('a"b', '"raw"', '1'))
            ->toBe('{"key":"a\"b","value":"raw","timestamp":1}');
    });

    it('leaves forward slashes unescaped', function () {
        expect(RawJson::envelope('a/b', 'null', '1'))->toContain('"key":"a/b"');
    });

    it('preserves a zero fraction when encoding for storage', function () {
        // Without JSON_PRESERVE_ZERO_FRACTION a float 1.0 would be stored as 1,
        // quietly changing a caller's number into an integer.
        expect(RawJson::encodeForStorage(1.0))->toBe('1.0');
    });

    it('leaves unicode unescaped when encoding for storage', function () {
        expect(RawJson::encodeForStorage('cafe'))->toBe('"cafe"');
    });

    it('builds a page with a cursor', function () {
        expect(RawJson::page(['{"a":1}', '{"b":2}'], 'abc'))
            ->toBe('{"data":[{"a":1},{"b":2}],"next_cursor":"abc"}');
    });

    it('builds a page with a null cursor on the last page', function () {
        expect(RawJson::page([], null))->toBe('{"data":[],"next_cursor":null}');
    });

    it('builds an unpaginated collection', function () {
        expect(RawJson::collection(['{"a":1}']))->toBe('{"data":[{"a":1}]}');
    });

    it('produces valid JSON for every envelope it builds', function (string $rawValue) {
        $json = RawJson::envelope('k', $rawValue, '1440568980.123456');

        expect(json_decode($json, true, 512, JSON_THROW_ON_ERROR))->toBeArray();
    })->with([['42'], ['null'], ['{}'], ['[]'], ['"text"'], ['{"nested":[1,2]}']]);
});

describe('ApiClient', function () {
    it('hashes a key with SHA-256', function () {
        expect(ApiClient::hash('abc'))->toBe(hash('sha256', 'abc'))
            ->and(ApiClient::hash('abc'))->toHaveLength(64);
    });

    it('generates a 48 character key', function () {
        expect(ApiClient::generateKey())->toHaveLength(48);
    });

    it('generates a different key every time', function () {
        $keys = array_map(fn () => ApiClient::generateKey(), range(1, 50));

        expect(array_unique($keys))->toHaveCount(50);
    });
});
