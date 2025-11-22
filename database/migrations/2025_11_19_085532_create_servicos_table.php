<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('descricao');
            $table->text('descricao_detalhada')->nullable();
            $table->decimal('valor_unitario', 15, 2)->default(0);

            $table->string('cnae', 20)->nullable();
            $table->string('codigo_servico_municipal', 50)->nullable();
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->string('tipo_servico', 50)->nullable();

            $table->boolean('ativo')->default(true);
            $table->text('observacoes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('codigo');
            $table->index('descricao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicos');
    }
};
