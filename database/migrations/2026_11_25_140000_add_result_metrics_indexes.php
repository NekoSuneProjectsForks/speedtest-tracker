<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->index(['status', 'created_at', 'id'], 'results_status_date_id_idx');
            $table->index(['status', 'id'], 'results_status_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropIndex('results_status_date_id_idx');
            $table->dropIndex('results_status_id_idx');
        });
    }
};
