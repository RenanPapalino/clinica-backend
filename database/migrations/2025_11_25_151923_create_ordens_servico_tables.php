<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ordens_servico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes');
            
            $table->string('codigo_os')->unique(); // Ex: OS-202310-001
            $table->string('competencia', 7); // Ex: 10/2023
            $table->date('data_emissao');
            
            $table->decimal('valor_total', 15, 2)->default(0);
            
            // Status do Fluxo: Pendente -> Aprovada -> Faturada
            $table->enum('status', ['pendente', 'aprovada', 'faturada', 'cancelada'])->default('pendente');
            
            // Se já foi faturada, guarda o ID da fatura gerada
            $table->foreignId('fatura_gerada_id')->nullable()->constrained('faturas');
            
            $table->text('observacoes')->nullable();
            $table->timestamps();
        });

        Schema::create('ordem_servico_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordens_servico')->onDelete('cascade');
            
            $table->string('descricao');
            $table->integer('quantidade')->default(1);
            $table->decimal('valor_unitario', 15, 2);
            $table->decimal('valor_total', 15, 2);
            
            // Metadados do SOC para conferência
            $table->string('unidade_soc')->nullable();
            $table->string('funcionario_soc')->nullable();
            $table->string('centro_custo_cliente')->nullable();
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ordem_servico_itens');
        Schema::dropIfExists('ordens_servico');
    }
};