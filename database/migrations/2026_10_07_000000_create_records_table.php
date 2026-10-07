<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 255);

            /*
             | "json", not "jsonb". jsonb is the usual default because it is
             | fast to query INSIDE a document. We never query inside a value;
             | we store it and hand it back. jsonb would discard key order,
             | duplicate keys and whitespace for an indexing benefit we never
             | use. "text" would be worse than both: it accepts any bytes, so a
             | malformed write would succeed and poison every later read.
             */
            $table->json('value');

            /*
             | The DATABASE sets this, not PHP. Several app containers can run
             | at once and their clocks drift apart, so a PHP timestamp can put
             | two writes to one key in the wrong order. The database is one
             | clock.
             |
             | clock_timestamp(), not now(). now() returns the TRANSACTION start
             | time and stays frozen for the whole transaction, so every row of
             | a bulk insert would collide on the unique index below.
             */
            $table->timestampTz('recorded_at', 6)
                ->default(DB::raw('clock_timestamp()'));

            /*
             | One key must never hold two records at one timestamp. The API
             | exposes microsecond precision, so without this a history listing
             | could show two identical timestamps and no query could address
             | the earlier record.
             |
             | This index also serves every read:
             |   - latest:       WHERE key = ? ORDER BY recorded_at DESC LIMIT 1
             |   - as-of:        WHERE key = ? AND recorded_at <= ? ORDER BY ...
             |   - history:      WHERE key = ? ORDER BY recorded_at DESC
             |   - get-all-keys: DISTINCT ON (key) ... ORDER BY key, recorded_at DESC
             | PostgreSQL scans a btree backwards, so DESC needs no separate
             | index. A second composite index would be dead weight.
             */
            $table->unique(['key', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
