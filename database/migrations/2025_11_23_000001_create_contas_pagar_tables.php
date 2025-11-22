<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. Fornecedores
        Schema::create('fornecedores', function (Blueprint $table) {
            $table->id();
            $table->string('razao_social');
            $table->string('nome_fantasia')->nullable();
            $table->string('cnpj')->unique()->nullable(); // Nullable pois pode ser pessoa física
            $table->string('cpf')->unique()->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->string('chave_pix')->nullable();
            $table->text('dados_bancarios')->nullable();
            $table->enum('status', ['ativo', 'inativo'])->default('ativo');
            $table->timestamps();
        });

        // 2. Categorias de Despesas (Plano de Contas de Saída)
        Schema::create('categorias_despesa', function (Blueprint $table) {
            $table->id();
            $table->string('descricao'); // Ex: Marketing, Pessoal, Impostos
            $table->string('codigo_contabil')->nullable(); // Para integração contábil futura
            $table->timestamps();
        });

        // 3. Despesas (Títulos a Pagar)
        Schema::create('despesas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fornecedor_id')->nullable()->constrained('fornecedores')->nullOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias_despesa')->nullOnDelete();
            
            $table->string('descricao'); // O que é? (Ex: Conta de Luz Maio)
            $table->decimal('valor', 15, 2);
            $table->date('data_emissao');
            $table->date('data_vencimento');
            $table->date('data_pagamento')->nullable();
            $table->decimal('valor_pago', 15, 2)->nullable();
            $table->enum('status', ['pendente', 'pago', 'atrasado', 'cancelado'])->default('pendente');
            
            // Inteligência de Arquivos
            $table->string('documento_url')->nullable(); // URL do PDF/Imagem no storage
            $table->string('documento_tipo')->nullable(); // boleto, nfse, recibo
            $table->string('codigo_barras')->nullable(); // Para pagamento
            
            $table->text('observacoes')->nullable();
            $table->timestamps();
        });
        
        // Seed inicial de categorias
        DB::table('categorias_despesa')->insert([
            ['descricao' => 'Custos com Pessoal'],
            ['descricao' => 'Impostos e Taxas'],
            ['descricao' => 'Infraestrutura (Aluguel, Luz, Água)'],
            ['descricao' => 'Tecnologia e Software'],
            ['descricao' => 'Marketing e Vendas'],
            ['descricao' => 'Serviços de Terceiros'],
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('despesas');
        Schema::dropIfExists('categorias_despesa');
        Schema::dropIfExists('fornecedores');
    }
};