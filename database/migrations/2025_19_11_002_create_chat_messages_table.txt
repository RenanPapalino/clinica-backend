<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_mensagens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->string('canal', 30)->default('web'); // web, whatsapp, n8n
            $table->string('origem', 30)->default('usuario'); // usuario, sistema, bot
            $table->string('identificador_externo')->nullable(); // ex: número whatsapp
            $table->text('mensagem');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'canal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_mensagens');
    }
};
