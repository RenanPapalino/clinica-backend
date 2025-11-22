<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')->constrained('clientes');

            $table->string('numero_fatura', 50)->unique();
            $table->date('data_emissao');
            $table->date('data_vencimento');
            $table->string('periodo_referencia', 50)->nullable();

            $table->decimal('valor_servicos', 15, 2)->default(0);
            $table->decimal('valor_descontos', 15, 2)->default(0);
            $table->decimal('valor_acrescimos', 15, 2)->default(0);
            $table->decimal('valor_iss', 15, 2)->default(0);
            $table->decimal('valor_total', 15, 2)->default(0);

            $table->string('status', 20)->default('aberta');
            $table->boolean('nfse_emitida')->default(false);

            $table->text('observacoes')->nullable();
            $table->json('metadata')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('cliente_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturas');
    }
};
