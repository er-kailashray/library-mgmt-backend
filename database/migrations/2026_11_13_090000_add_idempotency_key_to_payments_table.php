<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payments.idempotency_key: the app sends one key per "Save Payment" attempt and resends the same
 * key when it retries (e.g. after a timeout where the server had in fact saved it), so one payment
 * received is never recorded - and renewal dates never pushed forward - twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('notes');
            $table->unique(['library_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['library_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
