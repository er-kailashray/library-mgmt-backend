<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four plans priced to steer owners to longer ones, in line with the market (competitors charge about
 * ₹249–₹349 a month, ₹2,000–₹3,000 a year; researched Nov 2026). ₹399 a month is the anchor, 3 months is a small
 * step down (the decoy), 6 months is "Most popular" and yearly is ₹250 a month.
 * Libraries already on a plan keep what they paid until it ends; prices only affect the next purchase.
 */
return new class extends Migration
{
    private const PLANS = [
        ['code' => 'monthly', 'name' => 'Monthly', 'price' => 399, 'duration_months' => 1],
        ['code' => 'quarterly', 'name' => '3 Months', 'price' => 999, 'duration_months' => 3],
        ['code' => 'half_yearly', 'name' => '6 Months', 'price' => 1799, 'duration_months' => 6],
        ['code' => 'yearly', 'name' => 'Yearly', 'price' => 2999, 'duration_months' => 12],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PLANS as $plan) {
            $exists = DB::table('subscription_plans')->where('code', $plan['code'])->exists();

            if ($exists) {
                DB::table('subscription_plans')->where('code', $plan['code'])->update([...$plan, 'is_active' => true, 'updated_at' => $now]);
            } else {
                DB::table('subscription_plans')->insert([...$plan, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // New plans that were bought can't be removed (payments point at them); hide them instead.
        DB::table('subscription_plans')->whereIn('code', ['quarterly', 'half_yearly'])->update(['is_active' => false]);
        DB::table('subscription_plans')->where('code', 'monthly')->update(['price' => 299]);
        DB::table('subscription_plans')->where('code', 'yearly')->update(['price' => 2999]);
    }
};
