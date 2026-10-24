<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * For the Librum admin panel: settings we change without a deploy (e.g. the free trial length),
 * and a record of every change an admin makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });

        Schema::create('admin_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->foreignId('library_id')->nullable()->constrained()->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        // The trial that was in the server config (2 months) carries over as days.
        DB::table('app_settings')->insert([
            'key' => 'trial_days',
            'value' => (string) config('subscription.trial_days', 60),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activities');
        Schema::dropIfExists('app_settings');
    }
};
