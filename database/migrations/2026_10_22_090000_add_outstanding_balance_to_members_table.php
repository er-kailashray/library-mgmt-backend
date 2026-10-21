<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Dues owed at the moment a member left. Ended seats stop accruing dues, so without this
            // the amount would vanish; it's settled (collected and/or waived) on readmission.
            $table->decimal('outstanding_balance', 10, 2)->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('outstanding_balance');
        });
    }
};
