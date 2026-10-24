<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nothing about who sat where, or what a payment was for, is ever lost:
 * - batches and seats are soft-deleted (kept for our records, never shown or restored in the app);
 * - a seat allocation remembers the day it ended;
 * - each payment keeps a copy of the member's seats at the time it was recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('seats', fn (Blueprint $table) => $table->softDeletes());

        Schema::table('seat_allocations', function (Blueprint $table) {
            $table->date('ended_on')->nullable()->after('renewal_date');
        });
        // Best guess for seats that ended before this was recorded: the day the row last changed.
        DB::table('seat_allocations')->where('status', 'ended')->whereNull('ended_on')
            ->update(['ended_on' => DB::raw('DATE(updated_at)')]);

        Schema::table('payments', function (Blueprint $table) {
            $table->json('seats')->nullable()->after('period_end');
        });
        $this->backfillPaymentSeats();
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('seats'));
        Schema::table('seat_allocations', fn (Blueprint $table) => $table->dropColumn('ended_on'));
        Schema::table('seats', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('batches', fn (Blueprint $table) => $table->dropSoftDeletes());
    }

    /**
     * Older payments: the seats the member held on the payment date (started on or before it and not
     * yet ended), else the set they held most recently before it. Left empty when nothing matches.
     */
    private function backfillPaymentSeats(): void
    {
        $allocations = DB::table('seat_allocations')
            ->join('batches', 'batches.id', '=', 'seat_allocations.batch_id')
            ->join('seats', 'seats.id', '=', 'seat_allocations.seat_id')
            ->select(
                'seat_allocations.member_id', 'seat_allocations.start_date', 'seat_allocations.ended_on',
                'batches.id as batch_id', 'batches.name as batch_name', 'batches.monthly_fee',
                'seats.id as seat_id', 'seats.seat_number'
            )
            ->get()
            ->groupBy('member_id');

        DB::table('payments')->whereNull('seats')->orderBy('id')->each(function ($payment) use ($allocations) {
            $mine = $allocations->get($payment->member_id, collect());
            $paidOn = substr((string) $payment->payment_date, 0, 10);

            $held = $mine->filter(fn ($a) => $a->start_date <= $paidOn && ($a->ended_on === null || $a->ended_on >= $paidOn));
            if ($held->isEmpty()) {
                $before = $mine->filter(fn ($a) => $a->start_date <= $paidOn);
                $latest = $before->max('start_date');
                $held = $before->where('start_date', $latest);
            }
            if ($held->isEmpty()) {
                return;
            }

            DB::table('payments')->where('id', $payment->id)->update([
                'seats' => json_encode($held->map(fn ($a) => [
                    'batch_id' => $a->batch_id,
                    'batch_name' => $a->batch_name,
                    'seat_id' => $a->seat_id,
                    'seat_number' => (int) $a->seat_number,
                    'monthly_fee' => (float) $a->monthly_fee,
                ])->values()->all()),
            ]);
        });
    }
};
