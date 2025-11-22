<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. Ensinar o Plano de Contas
        Schema::table('planos_contas', function (Blueprint $table) {
            // Ex: ["posto", "gasolina", "abastecimento", "shell"] para a conta "Combustíveis"
            $table->json('palavras_chave')->nullable(); 
            
            // Flag para indicar contas que exigem atenção (ex: "Outros")
            $table->boolean('requer_auditoria')->default(false);
        });

        // 2. Tabela de "Aprendizado" (Histórico de Classificações)
        // O sistema salva aqui: "O usuário classificou 'Uber' como 'Viagens' 50 vezes".
        Schema::create('inteligencia_contabil_regras', function (Blueprint $table) {
            $table->id();
            $table->string('termo_origem'); // Ex: "Uber do Brasil"
            $table->foreignId('conta_sugerida_id')->constrained('planos_contas');
            $table->integer('confianca')->default(1); // Quantas vezes foi usado
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('inteligencia_contabil_regras');
        Schema::table('planos_contas', function (Blueprint $table) {
            $table->dropColumn(['palavras_chave', 'requer_auditoria']);
        });
    }
};