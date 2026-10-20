<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->enum('id_proof_type', ['aadhaar', 'pan', 'voter_id_dl', 'student_id', 'other'])->nullable()->after('phone');
            $table->string('id_proof_path')->nullable()->after('id_proof_type');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['id_proof_type', 'id_proof_path']);
        });
    }
};
