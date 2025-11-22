<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj', 14)->unique();
            $table->string('razao_social');
            $table->string('nome_fantasia')->nullable();
            $table->string('inscricao_municipal')->nullable();
            $table->string('inscricao_estadual')->nullable();

            $table->string('email')->nullable();
            $table->string('telefone', 30)->nullable();
            $table->string('celular', 30)->nullable();
            $table->string('site')->nullable();

            $table->string('cep', 10)->nullable();
            $table->string('logradouro')->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('complemento')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->string('uf', 2)->nullable();

            $table->string('status', 10)->default('ativo');
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->integer('prazo_pagamento_dias')->nullable();

            $table->text('observacoes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('cnpj');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
