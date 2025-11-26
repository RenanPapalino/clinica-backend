<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Ajuste Centro de Custo (Padronizar 'nome' -> 'descricao')
        Schema::table('centros_custo', function (Blueprint $table) {
            if (Schema::hasColumn('centros_custo', 'nome') && !Schema::hasColumn('centros_custo', 'descricao')) {
                $table->renameColumn('nome', 'descricao');
            }
            if (!Schema::hasColumn('centros_custo', 'codigo')) {
                $table->string('codigo')->nullable()->after('id');
            }
            if (!Schema::hasColumn('centros_custo', 'ativo')) {
                $table->boolean('ativo')->default(true);
            }
        });

        // Ajuste Plano de Contas
        Schema::table('planos_contas', function (Blueprint $table) {
            if (!Schema::hasColumn('planos_contas', 'natureza')) {
                $table->enum('natureza', ['fixa', 'variavel'])->nullable()->after('tipo');
            }
            if (!Schema::hasColumn('planos_contas', 'conta_contabil')) {
                $table->string('conta_contabil')->nullable();
            }
            if (!Schema::hasColumn('planos_contas', 'analitica')) {
                $table->boolean('analitica')->default(true);
            }
            if (!Schema::hasColumn('planos_contas', 'conta_pai_id')) {
                $table->foreignId('conta_pai_id')->nullable()->constrained('planos_contas')->nullOnDelete();
            }
        });
    }

    public function down()
    {
        // Reverter alterações (opcional para dev)
        Schema::table('centros_custo', function (Blueprint $table) {
            if (Schema::hasColumn('centros_custo', 'descricao')) {
                $table->renameColumn('descricao', 'nome');
            }
        });
    }
};