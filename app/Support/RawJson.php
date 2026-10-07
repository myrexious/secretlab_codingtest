<?php

namespace App\Support;

use Illuminate\Http\Response;

/**
 * Builds JSON responses by splicing stored values in as raw text.
 *
 * Every read path goes through here instead of response()->json(). The reason
 * is fidelity: json_encode() on a decoded value rewrites it. Splicing the bytes
 * that PostgreSQL returned means a caller gets back exactly what was stored,
 * down to object key order.
 */
final class RawJson
{
    /** Flags that keep an encode as close to the caller's input as PHP allows. */
    public const ENCODE_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    /**
     * Encode a value for storage.
     *
     * Known limit: Laravel decodes the request body before any code runs, so
     * this re-encodes. Type and object key order survive. Duplicate keys,
     * whitespace, and integers beyond 64 bits do not.
     */
    public static function encodeForStorage(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * One record envelope. $rawValue and $timestamp are inserted verbatim:
     * both already arrived from PostgreSQL as valid JSON text.
     */
    public static function envelope(string $key, string $rawValue, string $timestamp): string
    {
        return '{"key":'.json_encode($key, self::ENCODE_FLAGS)
            .',"value":'.$rawValue
            .',"timestamp":'.$timestamp
            .'}';
    }

    /** An unpaginated set of envelopes, used by bulk create. */
    public static function collection(array $envelopes): string
    {
        return '{"data":['.implode(',', $envelopes).']}';
    }

    /** A cursor-paginated page of envelopes. */
    public static function page(array $envelopes, ?string $nextCursor): string
    {
        return '{"data":['.implode(',', $envelopes).'],"next_cursor":'
            .($nextCursor === null ? 'null' : json_encode($nextCursor, self::ENCODE_FLAGS))
            .'}';
    }

    public static function response(string $json, int $status = 200): Response
    {
        return new Response($json, $status, ['Content-Type' => 'application/json']);
    }
}
