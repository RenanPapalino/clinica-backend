<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('servicos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('descricao', 200);
            $table->text('descricao_completa')->nullable();
            $table->decimal('valor_unitario', 10, 2);
            $table->decimal('custo_unitario', 10, 2)->nullable();
            $table->enum('categoria', ['exame', 'consulta', 'procedimento', 'outros'])->default('exame');
            $table->enum('status', ['ativo', 'inativo'])->default('ativo');
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('servicos'); }
};
