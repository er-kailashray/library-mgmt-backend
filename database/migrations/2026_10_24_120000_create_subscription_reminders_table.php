<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per reminder actually sent. Keyed on the end date it was about, so a renewal
        // (new ends_at) starts a fresh set and re-running the daily job never repeats one.
        Schema::create('subscription_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // ends_in_7, ends_in_3, ends_in_1, ended, read_only
            $table->dateTime('period_ends_at');
            $table->timestamp('sent_at');
            // Explicit name: the generated one is 65 chars, over MySQL's 64-char limit.
            $table->unique(['subscription_id', 'kind', 'period_ends_at'], 'subscription_reminders_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_reminders');
    }
};
