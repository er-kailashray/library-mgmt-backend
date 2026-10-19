<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // paid / partial — see App\Enums\PaymentStatus
            $table->string('status', 20)->default('paid')->after('amount');
            $table->index(['library_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['library_id', 'payment_date']);
            $table->dropColumn('status');
        });
    }
};
