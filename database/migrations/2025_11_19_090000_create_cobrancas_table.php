<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('fatura_id')->nullable()->constrained('faturas');
            $table->foreignId('titulo_id')->nullable()->constrained('titulos');

            $table->string('meio', 30)->nullable();   // email, whatsapp, boleto, pix
            $table->string('status', 20)->default('pendente'); // pendente, enviada, falha, paga
            $table->string('canal', 30)->nullable();  // n8n, manual, api_banco

            $table->string('descricao')->nullable();
            $table->datetime('data_envio')->nullable();
            $table->datetime('data_pagamento')->nullable();
            $table->decimal('valor_cobrado', 15, 2)->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['cliente_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobrancas');
    }
};
