<?php

namespace App\Support;

/**
 * Opaque pagination cursors.
 *
 * base64url, so a cursor survives a query string without encoding and does not
 * invite callers to build one by hand. There is no signing: a cursor only picks
 * a starting point in data the caller may already read.
 */
final class Cursor
{
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** Returns null when the cursor is not valid base64url. */
    public static function decode(string $cursor): ?string
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
