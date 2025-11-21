#!/bin/bash
cd /var/www/clinica-backend

echo "🔧 Criando migrations na ordem correta..."

# Esperar 1 segundo entre cada migration para garantir ordem
sleep 1

# 2. Serviços
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_servicos_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('descricao', 200);
            $table->text('descricao_completa')->nullable();
            $table->decimal('valor_unitario', 10, 2);
            $table->decimal('custo_unitario', 10, 2)->nullable();
            $table->string('codigo_servico_municipio', 20)->nullable();
            $table->string('cnae', 20)->nullable();
            $table->decimal('aliquota_iss', 5, 2)->default(5.00);
            $table->enum('categoria', ['exame', 'consulta', 'procedimento', 'outros'])->default('exame');
            $table->enum('status', ['ativo', 'inativo'])->default('ativo');
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('codigo');
            $table->index('status');
            $table->index('categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicos');
    }
};
MIGRATION

sleep 2

# 3. Faturas (ANTES de fatura_itens!)
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_faturas_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->string('numero_fatura', 50)->unique();
            $table->date('data_emissao');
            $table->date('data_vencimento');
            $table->string('periodo_referencia', 20);
            $table->decimal('valor_servicos', 12, 2);
            $table->decimal('valor_descontos', 12, 2)->default(0);
            $table->decimal('valor_acrescimos', 12, 2)->default(0);
            $table->decimal('valor_iss', 12, 2)->default(0);
            $table->decimal('valor_total', 12, 2);
            $table->enum('status', ['rascunho', 'emitida', 'cancelada'])->default('rascunho');
            $table->boolean('nfse_emitida')->default(false);
            $table->text('observacoes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('numero_fatura');
            $table->index('cliente_id');
            $table->index('data_emissao');
            $table->index('status');
            $table->index('periodo_referencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturas');
    }
};
MIGRATION

sleep 2

# 4. Fatura Itens (DEPOIS de faturas!)
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_fatura_itens_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fatura_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fatura_id')->constrained('faturas')->onDelete('cascade');
            $table->foreignId('servico_id')->nullable()->constrained('servicos');
            $table->integer('item_numero')->default(1);
            $table->string('descricao', 200);
            $table->integer('quantidade')->default(1);
            $table->decimal('valor_unitario', 10, 2);
            $table->decimal('valor_total', 12, 2);
            $table->date('data_realizacao')->nullable();
            $table->string('funcionario', 100)->nullable();
            $table->string('matricula', 50)->nullable();
            $table->timestamps();
            
            $table->index('fatura_id');
            $table->index('servico_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fatura_itens');
    }
};
MIGRATION

sleep 2

# 5. NFSe Lotes (ANTES de nfse!)
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_nfse_lotes_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse_lotes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_lote', 50)->unique();
            $table->string('protocolo_envio', 50)->nullable();
            $table->dateTime('data_criacao');
            $table->dateTime('data_envio')->nullable();
            $table->dateTime('data_processamento')->nullable();
            $table->integer('quantidade_nfse')->default(0);
            $table->integer('quantidade_processadas')->default(0);
            $table->integer('quantidade_autorizadas')->default(0);
            $table->integer('quantidade_erros')->default(0);
            $table->decimal('valor_total_servicos', 14, 2)->default(0);
            $table->decimal('valor_total_iss', 14, 2)->default(0);
            $table->enum('status', ['criado', 'enviando', 'processando', 'finalizado', 'erro'])->default('criado');
            $table->text('mensagem_erro')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('numero_lote');
            $table->index('protocolo_envio');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_lotes');
    }
};
MIGRATION

sleep 2

# 6. NFSe
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_nfse_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fatura_id')->constrained('faturas');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('lote_id')->nullable()->constrained('nfse_lotes');
            $table->string('numero_nfse', 50)->nullable()->unique();
            $table->string('codigo_verificacao', 50)->nullable();
            $table->string('protocolo', 50)->nullable();
            $table->dateTime('data_emissao')->nullable();
            $table->dateTime('data_envio')->nullable();
            $table->dateTime('data_autorizacao')->nullable();
            $table->decimal('valor_servicos', 12, 2);
            $table->decimal('valor_deducoes', 12, 2)->default(0);
            $table->decimal('valor_iss', 12, 2);
            $table->decimal('aliquota_iss', 5, 2);
            $table->decimal('valor_liquido', 12, 2);
            $table->enum('status', ['pendente', 'processando', 'autorizada', 'cancelada', 'erro'])->default('pendente');
            $table->string('codigo_servico', 20)->nullable();
            $table->text('discriminacao')->nullable();
            $table->text('xml_nfse')->nullable();
            $table->string('pdf_url', 500)->nullable();
            $table->text('mensagem_erro')->nullable();
            $table->json('detalhes_erro')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('numero_nfse');
            $table->index('protocolo');
            $table->index('fatura_id');
            $table->index('cliente_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse');
    }
};
MIGRATION

sleep 2

# 7. Atualizar Faturas (adicionar foreign key para nfse)
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_add_nfse_foreign_to_faturas.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->foreignId('nfse_id')->nullable()->after('nfse_emitida')->constrained('nfse');
        });
    }

    public function down(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->dropForeign(['nfse_id']);
            $table->dropColumn('nfse_id');
        });
    }
};
MIGRATION

sleep 2

# 8. Títulos
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_titulos_table.php << 'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('fatura_id')->nullable()->constrained('faturas');
            $table->string('numero_titulo', 50)->unique();
            $table->string('nosso_numero', 50)->nullable();
            $table->date('data_emissao');
            $table->date('data_vencimento');
            $table->date('data_pagamento')->nullable();
            $table->decimal('valor_original', 12, 2);
            $table->decimal('valor_juros', 12, 2)->default(0);
            $table->decimal('valor_multa', 12, 2)->default(0);
            $table->decimal('valor_desconto', 12, 2)->default(0);
            $table->decimal('valor_pago', 12, 2)->nullable();
            $table->decimal('valor_saldo', 12, 2);
            $table->enum('status', ['aberto', 'vencido', 'pago_parcial', 'pago', 'cancelado'])->default('aberto');
            $table->enum('forma_pagamento', ['boleto', 'pix', 'transferencia', 'dinheiro', 'cartao', 'outros'])->nullable();
            $table->string('codigo_barras', 100)->nullable();
            $table->string('linha_digitavel', 100)->nullable();
            $table->string('url_boleto', 500)->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('numero_titulo');
            $table->index('cliente_id');
            $table->index('fatura_id');
            $table->index('data_vencimento');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titulos');
    }
};
MIGRATION

sleep 2

# 9. Título Baixas
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

sleep 2

# 10. Cobranças
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobrancas');
    }
};
MIGRATION

sleep 2

# 11. Plano de Contas
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

sleep 2

# 12. Centros de Custo
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

sleep 2

# 13. Configurações
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
            $table->string('tipo', 50)->default('string');
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

echo "✅ Migrations criadas na ordem correta!"
ls -lah database/migrations/ | tail -15
