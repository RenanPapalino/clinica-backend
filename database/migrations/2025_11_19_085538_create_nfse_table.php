<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('nfse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fatura_id')->constrained('faturas');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->string('numero_nfse', 50)->nullable()->unique();
            $table->string('protocolo', 50)->nullable();
            $table->decimal('valor_servicos', 12, 2);
            $table->decimal('valor_iss', 12, 2);
            $table->enum('status', ['pendente', 'autorizada', 'cancelada', 'erro'])->default('pendente');
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('nfse'); }
};
