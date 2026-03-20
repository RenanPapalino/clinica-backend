<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('titulos', 'descricao')) {
            Schema::table('titulos', function (Blueprint $table) {
                $table->string('descricao')->nullable()->after('fatura_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('titulos', 'descricao')) {
            Schema::table('titulos', function (Blueprint $table) {
                $table->dropColumn('descricao');
            });
        }
    }
};
