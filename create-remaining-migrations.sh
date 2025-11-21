#!/bin/bash
cd /var/www/clinica-backend

echo "📦 Criando migrations restantes..."

# Migration: Títulos Baixas
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_titulo_baixas_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titulo_baixas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titulo_id')->constrained('titulos')->onDelete('cascade');
            $table->date('data_baixa');
            $table->decimal('valor_baixa', 12, 2);
            $table->decimal('valor_juros', 12, 2)->default(0);
            $table->decimal('valor_multa', 12, 2)->default(0);
            $table->decimal('valor_desconto', 12, 2)->default(0);
            $table->enum('forma_pagamento', ['boleto', 'pix', 'transferencia', 'dinheiro', 'cartao', 'outros']);
            $table->text('observacoes')->nullable();
            $table->timestamps();
            
            $table->index('titulo_id');
            $table->index('data_baixa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titulo_baixas');
    }
};
MIGRATION

sleep 1

# Migration: Cobranças
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_cobrancas_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobrancas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titulo_id')->constrained('titulos');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->enum('tipo', ['email', 'whatsapp', 'sms'])->default('email');
            $table->dateTime('data_envio');
            $table->enum('status', ['enviada', 'entregue', 'lida', 'erro'])->default('enviada');
            $table->string('destinatario', 200);
            $table->text('mensagem')->nullable();
            $table->text('mensagem_erro')->nullable();
            $table->timestamps();
            
            $table->index('titulo_id');
            $table->index('cliente_id');
            $table->index('status');
            $table->index('data_envio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobrancas');
    }
};
MIGRATION

sleep 1

# Migration: Remessas Bancárias
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_remessas_bancarias_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remessas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->string('numero_remessa', 50)->unique();
            $table->date('data_geracao');
            $table->date('data_envio')->nullable();
            $table->integer('quantidade_titulos')->default(0);
            $table->decimal('valor_total', 14, 2)->default(0);
            $table->enum('status', ['gerada', 'enviada', 'processada'])->default('gerada');
            $table->string('arquivo_nome', 200)->nullable();
            $table->string('arquivo_path', 500)->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
            
            $table->index('numero_remessa');
            $table->index('data_geracao');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remessas_bancarias');
    }
};
MIGRATION

sleep 1

# Migration: Plano de Contas
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_plano_contas_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_contas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('descricao', 200);
            $table->foreignId('conta_pai_id')->nullable()->constrained('plano_contas');
            $table->enum('tipo', ['receita', 'despesa']);
            $table->enum('natureza', ['operacional', 'nao_operacional'])->default('operacional');
            $table->integer('nivel')->default(1);
            $table->boolean('aceita_lancamento')->default(true);
            $table->enum('status', ['ativo', 'inativo'])->default('ativo');
            $table->timestamps();
            
            $table->index('codigo');
            $table->index('tipo');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_contas');
    }
};
MIGRATION

sleep 1

# Migration: Centros de Custo
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_centros_custo_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centros_custo', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('descricao', 200);
            $table->text('descricao_completa')->nullable();
            $table->enum('tipo', ['unidade', 'departamento', 'projeto'])->default('unidade');
            $table->enum('status', ['ativo', 'inativo'])->default('ativo');
            $table->timestamps();
            
            $table->index('codigo');
            $table->index('tipo');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centros_custo');
    }
};
MIGRATION

sleep 1

# Migration: Configurações
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_configuracoes_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 100)->unique();
            $table->text('valor')->nullable();
            $table->string('tipo', 50)->default('string'); // string, number, boolean, json
            $table->string('grupo', 100)->default('geral');
            $table->string('descricao', 500)->nullable();
            $table->timestamps();
            
            $table->index('chave');
            $table->index('grupo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
    }
};
MIGRATION

echo "✅ Migrations criadas!"
ls -lah database/migrations/ | tail -10
