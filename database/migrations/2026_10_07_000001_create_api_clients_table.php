<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            /*
             | The SHA-256 of the key, never the key itself. A leaked database
             | dump then yields no usable credentials. Lookup is by hash, so the
             | unique index doubles as the lookup index.
             |
             | SHA-256 and not bcrypt: an API key is 48 random characters, so
             | there is no low-entropy password to slow a guesser down, and a
             | bcrypt comparison would need a table scan instead of an index hit.
             */
            $table->string('key_hash', 64)->unique();

            $table->unsignedInteger('rate_limit_per_minute');
            $table->timestamp('created_at')->useCurrent();

            // Null while active. Revoking keeps the row, so a revoked key can
            // never be minted again by chance.
            $table->timestampTz('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
