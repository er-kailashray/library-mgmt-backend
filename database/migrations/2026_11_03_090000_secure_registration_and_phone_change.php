<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - users.registration_token_hash: a sign-up in progress belongs to the device that started it.
 *   Registering the same number again elsewhere replaces it, so a code typed on the first device
 *   can't verify an account someone else has just re-registered with their own password.
 * - otp_verifications.target: the number a code was sent to when it isn't the account's own
 *   (changing the owner's phone number sends the code to the new number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('registration_token_hash', 64)->nullable()->after('phone_verified_at');
        });

        Schema::table('otp_verifications', function (Blueprint $table) {
            $table->string('target', 20)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('otp_verifications', fn (Blueprint $table) => $table->dropColumn('target'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('registration_token_hash'));
    }
};
