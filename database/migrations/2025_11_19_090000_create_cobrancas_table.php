<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fatura_id')->constrained('faturas')->onDelete('cascade');
            $table->timestamp('data_envio');
            $table->enum('canal', ['email', 'whatsapp', 'sms'])->default('email');
            $table->string('destinatario');
            $table->enum('status', ['enviada', 'erro', 'pendente'])->default('pendente');
            $table->integer('tentativas')->default(1);
            $table->text('mensagem_erro')->nullable();
            $table->timestamps();

            $table->index('fatura_id');
            $table->index('status');
            $table->index('data_envio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cobrancas');
    }
};
