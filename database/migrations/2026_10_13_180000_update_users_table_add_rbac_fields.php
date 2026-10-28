<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Updates users table with phone, role_id (FK -> roles.id), branch_id, and status columns.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('password');
            $table->foreignId('role_id')
                ->nullable()
                ->after('phone')
                ->constrained('roles')
                ->nullOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->after('role_id');
            $table->string('status', 20)->default('active')->after('branch_id');

            if (Schema::hasColumn('users', 'email_verified_at')) {
                $table->dropColumn('email_verified_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role_id')) {
                $table->dropForeign(['role_id']);
            }
            $table->dropColumn(['phone', 'role_id', 'branch_id', 'status']);
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }
};
