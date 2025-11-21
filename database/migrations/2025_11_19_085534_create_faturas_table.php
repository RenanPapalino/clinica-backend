<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('faturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->string('numero_fatura', 50)->unique();
            $table->date('data_emissao');
            $table->date('data_vencimento');
            $table->string('periodo_referencia', 20);
            $table->decimal('valor_servicos', 12, 2);
            $table->decimal('valor_total', 12, 2);
            $table->enum('status', ['rascunho', 'emitida', 'cancelada'])->default('rascunho');
            $table->boolean('nfse_emitida')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('faturas'); }
};
