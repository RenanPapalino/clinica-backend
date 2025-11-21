<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('titulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('fatura_id')->nullable()->constrained('faturas');
            $table->string('numero_titulo', 50)->unique();
            $table->date('data_emissao');
            $table->date('data_vencimento');
            $table->decimal('valor_original', 12, 2);
            $table->decimal('valor_saldo', 12, 2);
            $table->enum('status', ['aberto', 'vencido', 'pago', 'cancelado'])->default('aberto');
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('titulos'); }
};
