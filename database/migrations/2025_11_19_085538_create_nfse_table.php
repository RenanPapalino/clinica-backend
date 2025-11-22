<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fatura_id')->constrained('faturas');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->unsignedBigInteger('lote_id')->nullable();

            $table->string('numero_nfse', 50)->nullable();
            $table->string('codigo_verificacao', 100)->nullable();
            $table->string('protocolo', 100)->nullable();

            $table->datetime('data_emissao')->nullable();
            $table->datetime('data_envio')->nullable();
            $table->datetime('data_autorizacao')->nullable();

            $table->decimal('valor_servicos', 15, 2)->nullable();
            $table->decimal('valor_deducoes', 15, 2)->nullable();
            $table->decimal('valor_iss', 15, 2)->nullable();
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->decimal('valor_liquido', 15, 2)->nullable();

            $table->string('status', 20)->default('pendente'); // emitida, cancelada, erro, pendente
            $table->string('codigo_servico', 50)->nullable();
            $table->text('discriminacao')->nullable();

            $table->longText('xml_nfse')->nullable();
            $table->string('pdf_url')->nullable();

            $table->string('mensagem_erro')->nullable();
            $table->json('detalhes_erro')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['cliente_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse');
    }
};
