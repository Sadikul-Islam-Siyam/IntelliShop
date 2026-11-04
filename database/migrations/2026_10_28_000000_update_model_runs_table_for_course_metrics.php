<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_runs', function (Blueprint $table) {
            $table->decimal('sse', 12, 3)->nullable()->after('folds');
            $table->decimal('rse', 10, 3)->nullable()->after('sse');
            $table->decimal('r_squared', 8, 3)->nullable()->after('rse');
            $table->decimal('baseline_sse', 12, 3)->nullable()->after('baseline_model');
        });
    }

    public function down(): void
    {
        Schema::table('model_runs', function (Blueprint $table) {
            $table->dropColumn(['sse', 'rse', 'r_squared', 'baseline_sse']);
        });
    }
};
