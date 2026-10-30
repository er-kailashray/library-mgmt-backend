<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A seat taken out of use for a while (broken chair, repairs, kept for staff): in one batch, or in
 * every batch when `batch_id` is null, from `starts_on` until `ends_on` (null = until unblocked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('reason', 60)->nullable();
            $table->timestamps();

            $table->index(['library_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_blocks');
    }
};
