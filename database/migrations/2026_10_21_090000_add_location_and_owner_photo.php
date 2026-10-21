<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('total_seats');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_photo')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->dropColumn(['address', 'latitude', 'longitude']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_photo');
        });
    }
};
