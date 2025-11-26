<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            // Campos específicos para não misturar com o campo 'valor' genérico
            $table->text('certificado_digital_path')->nullable()->after('valor'); // Caminho do .pfx
            $table->string('certificado_senha')->nullable()->after('certificado_digital_path');
            
            // Credenciais Prefeitura (NFS-e)
            $table->string('prefeitura_usuario')->nullable()->after('certificado_senha');
            $table->string('prefeitura_senha')->nullable()->after('prefeitura_usuario');
            
            // Credenciais Bancárias (Itaú/BB)
            $table->string('banco_client_id')->nullable()->after('prefeitura_senha');
            $table->string('banco_client_secret')->nullable()->after('banco_client_id');
            $table->text('banco_certificado_crt')->nullable(); // Caminho do .crt (mTLS)
            $table->text('banco_certificado_key')->nullable(); // Caminho da .key (mTLS)
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn([
                'certificado_digital_path', 'certificado_senha',
                'prefeitura_usuario', 'prefeitura_senha',
                'banco_client_id', 'banco_client_secret',
                'banco_certificado_crt', 'banco_certificado_key'
            ]);
        });
    }
};