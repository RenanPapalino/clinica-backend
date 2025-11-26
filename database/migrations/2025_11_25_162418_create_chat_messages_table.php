<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Vincula ao usuário logado
            $table->string('session_id')->nullable(); // Para agrupar conversas
            $table->enum('role', ['user', 'assistant', 'system']); // Quem falou?
            $table->text('content'); // A mensagem
            $table->json('metadata')->nullable(); // Dados extras (ex: ID do cliente citado)
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('chat_messages');
    }
};