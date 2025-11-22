<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fatura_itens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fatura_id')->constrained('faturas');
            $table->foreignId('servico_id')->constrained('servicos');

            $table->integer('item_numero')->default(1);
            $table->string('descricao');
            $table->integer('quantidade')->default(1);
            $table->decimal('valor_unitario', 15, 2)->default(0);
            $table->decimal('valor_total', 15, 2)->default(0);
            $table->date('data_realizacao')->nullable();
            $table->string('funcionario', 100)->nullable();
            $table->string('matricula', 50)->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('fatura_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fatura_itens');
    }
};
