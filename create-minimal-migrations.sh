#!/bin/bash
set -e

echo "📦 Criando migrations essenciais..."

cd /var/www/clinica-backend

# ============================================
# MIGRATION 1: CLIENTES
# ============================================
sleep 1
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_clientes_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj', 20)->unique();
            $table->string('razao_social', 200);
            $table->string('nome_fantasia', 200)->nullable();
            $table->string('inscricao_municipal', 50)->nullable();
            $table->string('inscricao_estadual', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('celular', 20)->nullable();
            $table->string('site', 200)->nullable();
            $table->string('cep', 10)->nullable();
            $table->string('logradouro', 200)->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('complemento', 100)->nullable();
            $table->string('bairro', 100)->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('regime_tributario', 50)->nullable();
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->integer('prazo_pagamento_dias')->nullable();
            $table->text('observacoes')->nullable();
            $table->enum('status', ['ativo', 'inativo'])->default('ativo');
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('clientes'); }
};
PHPEOF

# ============================================
# MIGRATION 2: SERVICOS
# ============================================
sleep 2
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_servicos_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
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
        });
    }
    public function down(): void { Schema::dropIfExists('servicos'); }
};
PHPEOF

# ============================================
# MIGRATION 3: FATURAS
# ============================================
sleep 2
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_faturas_table.php << 'PHPEOF'
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
        });
    }
    public function down(): void { Schema::dropIfExists('faturas'); }
};
PHPEOF

# ============================================
# MIGRATION 4: FATURA ITENS
# ============================================
sleep 2
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_fatura_itens_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
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
        });
    }
    public function down(): void { Schema::dropIfExists('fatura_itens'); }
};
PHPEOF

# ============================================
# MIGRATION 5: NFSE
# ============================================
sleep 2
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_nfse_table.php << 'PHPEOF'
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
        });
    }
    public function down(): void { Schema::dropIfExists('nfse'); }
};
PHPEOF

# ============================================
# MIGRATION 6: TITULOS
# ============================================
sleep 2
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_titulos_table.php << 'PHPEOF'
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
        });
    }
    public function down(): void { Schema::dropIfExists('titulos'); }
};
PHPEOF

# ============================================
# MIGRATION 7: CONFIGURACOES
# ============================================
sleep 2
cat > database/migrations/$(date +%Y_%m_%d_%H%M%S)_create_configuracoes_table.php << 'PHPEOF'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 100)->unique();
            $table->text('valor')->nullable();
            $table->string('tipo', 50)->default('string');
            $table->string('grupo', 100)->default('geral');
            $table->string('descricao', 500)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('configuracoes'); }
};
PHPEOF

echo "✅ Migrations essenciais criadas!"
ls -lah database/migrations/ | tail -10
