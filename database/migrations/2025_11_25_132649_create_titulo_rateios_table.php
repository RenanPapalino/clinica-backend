<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verifica se a tabela JÁ existe antes de tentar criar
        if (!Schema::hasTable('titulo_rateios')) {
            Schema::create('titulo_rateios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('titulo_id')->constrained('titulos')->onDelete('cascade');
                
                // O rateio pode ser por Centro de Custo OU por Cliente (Repasse)
                $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo');
                $table->foreignId('plano_conta_id')->nullable()->constrained('planos_contas');
                $table->foreignId('cliente_id')->nullable()->constrained('clientes'); 
                
                $table->decimal('valor', 15, 2);
                $table->decimal('percentual', 5, 2)->nullable(); // Ex: 50.00
                $table->string('historico')->nullable(); // Ex: "Rateio referente filial X"
                
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('titulo_rateios');
    }
};