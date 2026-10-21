<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            /*
             | A second clock column, deliberately independent of recorded_at.
             | recorded_at stays DB-assigned and collision-free (it is still the
             | tie-break and the audit order); effective_at is the business
             | instant a value takes hold, and two rows ARE allowed to share one
             | (two edits can target the same go-live second). The column default
             | only feeds existing rows below and any insert that never mentions
             | this column (e.g. a test writing straight to the table); the API
             | always sets it explicitly. See CONTEXT.md and docs/adr/0001.
             */
            $table->timestampTz('effective_at', 6)->default(DB::raw('clock_timestamp()'));
        });

        // Every row written before this feature existed was already live from
        // the moment it was recorded.
        DB::statement('update records set effective_at = recorded_at');

        Schema::table('records', function (Blueprint $table) {
            $table->index(['key', 'effective_at']);
        });

        Schema::create('cancelled_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 255);

            // Deliberately not a foreign key to records.id: a cancellation
            // silences every row sharing this (key, effective_at), not one row
            // by id, because two edits can target the same go-live instant and
            // cancelling the slot must suppress both. See CONTEXT.md.
            $table->timestampTz('effective_at', 6);

            $table->timestampTz('cancelled_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->unique(['key', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancelled_schedules');

        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex(['key', 'effective_at']);
            $table->dropColumn('effective_at');
        });
    }
};
