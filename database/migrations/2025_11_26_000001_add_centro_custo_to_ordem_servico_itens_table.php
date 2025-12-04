<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordem_servico_itens', function (Blueprint $table) {
            if (!Schema::hasColumn('ordem_servico_itens', 'centro_custo')) {
                $table->string('centro_custo')->nullable()->after('centro_custo_cliente');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ordem_servico_itens', function (Blueprint $table) {
            if (Schema::hasColumn('ordem_servico_itens', 'centro_custo')) {
                $table->dropColumn('centro_custo');
            }
        });
    }
};
