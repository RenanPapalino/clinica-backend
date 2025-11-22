<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titulos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('fatura_id')->nullable()->constrained('faturas');

            $table->string('numero_titulo', 50);
            $table->string('nosso_numero', 50)->nullable();

            $table->date('data_emissao');
            $table->date('data_vencimento');
            $table->date('data_pagamento')->nullable();

            $table->decimal('valor_original', 15, 2);
            $table->decimal('valor_juros', 15, 2)->default(0);
            $table->decimal('valor_multa', 15, 2)->default(0);
            $table->decimal('valor_desconto', 15, 2)->default(0);
            $table->decimal('valor_pago', 15, 2)->default(0);
            $table->decimal('valor_saldo', 15, 2)->default(0);

            $table->string('status', 20)->default('aberto');
            $table->string('forma_pagamento', 30)->nullable();

            $table->string('codigo_barras')->nullable();
            $table->string('linha_digitavel')->nullable();
            $table->string('url_boleto')->nullable();

            $table->text('observacoes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['cliente_id', 'status']);
            $table->index(['data_vencimento', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titulos');
    }
};
