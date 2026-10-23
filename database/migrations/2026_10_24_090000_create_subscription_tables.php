<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // monthly, yearly
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('duration_months');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One per library. Its state (trial / active / grace / expired) is derived from ends_at.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans'); // null while on trial
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('provider')->nullable(); // razorpay
            $table->string('provider_subscription_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('provider');
            $table->string('provider_order_id')->nullable();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('status', 20); // created, paid, failed, refunded
            $table->timestamp('paid_at')->nullable();
            $table->string('invoice_number')->nullable()->unique();
            $table->json('payload')->nullable(); // raw provider data, for support/audit
            $table->timestamps();
        });

        $now = now();
        DB::table('subscription_plans')->insert([
            ['code' => 'monthly', 'name' => 'Monthly', 'price' => 299, 'duration_months' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'yearly', 'name' => 'Yearly', 'price' => 2999, 'duration_months' => 12, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Libraries that exist before launch get a fresh trial from today.
        $trialEnds = $now->copy()->addMonthsNoOverflow((int) config('subscription.trial_months', 2));
        DB::table('libraries')->orderBy('id')->each(function ($library) use ($now, $trialEnds) {
            DB::table('subscriptions')->insert([
                'library_id' => $library->id,
                'starts_at' => $now,
                'ends_at' => $trialEnds,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
