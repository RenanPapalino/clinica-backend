<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('codigo_soc')->nullable()->index()->after('id'); // ID único no SOC
            $table->dateTime('ultima_sincronizacao_soc')->nullable();
            
            // Garante que os campos de endereço existam (caso não tenha criado antes)
            if (!Schema::hasColumn('clientes', 'endereco')) $table->string('endereco')->nullable();
            if (!Schema::hasColumn('clientes', 'numero')) $table->string('numero')->nullable();
            if (!Schema::hasColumn('clientes', 'bairro')) $table->string('bairro')->nullable();
            if (!Schema::hasColumn('clientes', 'cep')) $table->string('cep')->nullable();
        });
    }

    public function down()
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['codigo_soc', 'ultima_sincronizacao_soc']);
        });
    }
};