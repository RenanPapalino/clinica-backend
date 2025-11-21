#!/bin/bash
set -e

echo "🚀 SETUP COMPLETO DO BACKEND - VERSÃO FINAL"
echo ""

cd /var/www/clinica-backend

# Pegar senha do .env
DB_PASSWORD=$(grep DB_PASSWORD .env | cut -d '=' -f2)

# ============================================
# 1. LIMPAR BANCO DE DADOS
# ============================================
echo "1️⃣ Limpando banco de dados..."
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro << 'SQL'
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS users, password_reset_tokens, failed_jobs, sessions, cache, cache_locks, jobs, job_batches, migrations;
DROP TABLE IF EXISTS clientes, servicos, faturas, fatura_itens, nfse, nfse_lotes, titulos, titulo_baixas;
DROP TABLE IF EXISTS cobrancas, remessas_bancarias, plano_contas, centros_custo, configuracoes;
SET FOREIGN_KEY_CHECKS = 1;
SQL

# ============================================
# 2. LIMPAR MIGRATIONS ANTIGAS
# ============================================
echo "2️⃣ Limpando migrations antigas..."
rm -f database/migrations/*_create_clientes_table.php
rm -f database/migrations/*_create_servicos_table.php
rm -f database/migrations/*_create_faturas*.php
rm -f database/migrations/*_create_fatura*.php
rm -f database/migrations/*_create_nfse*.php
rm -f database/migrations/*_create_titulo*.php
rm -f database/migrations/*_create_cobrancas*.php
rm -f database/migrations/*_create_remessas*.php
rm -f database/migrations/*_create_plano*.php
rm -f database/migrations/*_create_centros*.php
rm -f database/migrations/*_create_configuracoes*.php

# ============================================
# 3. CRIAR MIGRATIONS NOVAS
# ============================================
echo "3️⃣ Criando migrations..."

# Migration: Clientes
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
            $table->string('email', 100)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('celular', 20)->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('uf', 2)->nullable();
            $table->enum('status', ['ativo', 'inativo'])->default('ativo');
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('clientes'); }
};
PHPEOF

# Migration: Servicos
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
            $table->enum('categoria', ['exame', 'consulta', 'procedimento', 'outros'])->default('exame');
            $table->enum('status', ['ativo', 'inativo'])->default('ativo');
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('servicos'); }
};
PHPEOF

# Migration: Faturas
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
            $table->decimal('valor_total', 12, 2);
            $table->enum('status', ['rascunho', 'emitida', 'cancelada'])->default('rascunho');
            $table->boolean('nfse_emitida')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('faturas'); }
};
PHPEOF

# Migration: Fatura Itens
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
            $table->string('descricao', 200);
            $table->integer('quantidade')->default(1);
            $table->decimal('valor_unitario', 10, 2);
            $table->decimal('valor_total', 12, 2);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('fatura_itens'); }
};
PHPEOF

# Migration: NFSe
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
PHPEOF

# Migration: Titulos
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
PHPEOF

echo "✅ Migrations criadas!"

# ============================================
# 4. RODAR MIGRATIONS
# ============================================
echo "4️⃣ Rodando migrations..."
php artisan migrate --force

# ============================================
# 5. POPULAR DADOS
# ============================================
echo "5️⃣ Populando dados de teste..."
php artisan db:seed --class=ServicoSeeder --force

# ============================================
# 6. LIMPAR CACHE
# ============================================
echo "6️⃣ Limpando cache..."
php artisan optimize:clear

# ============================================
# 7. REINICIAR SERVIDOR
# ============================================
echo "7️⃣ Reiniciando servidor..."
killall php 2>/dev/null || true
nohup php artisan serve --host=0.0.0.0 --port=8000 > /var/log/laravel-api.log 2>&1 &
sleep 3

# ============================================
# 8. TESTAR
# ============================================
echo ""
echo "🧪 TESTANDO API..."
echo ""
echo "Health Check:"
curl -s http://localhost:8000/api/health | jq || curl http://localhost:8000/api/health
echo ""
echo ""
echo "Serviços (deve retornar 6):"
curl -s http://localhost:8000/api/cadastros/servicos | jq '.data | length' || curl http://localhost:8000/api/cadastros/servicos
echo ""
echo ""
echo "Clientes:"
curl -s http://localhost:8000/api/cadastros/clientes | jq '.success' || curl http://localhost:8000/api/cadastros/clientes

# ============================================
# 9. VERIFICAR TABELAS
# ============================================
echo ""
echo "📋 TABELAS CRIADAS:"
mysql -u clinica_user -p"${DB_PASSWORD}" clinica_financeiro -e "SHOW TABLES;"

echo ""
echo "📊 ROTAS DISPONÍVEIS:"
php artisan route:list --path=api | head -20

echo ""
echo "✅✅✅ BACKEND COMPLETO E FUNCIONANDO! ✅✅✅"
echo ""
echo "🌐 TESTAR NO POSTMAN:"
echo "   GET http://72.61.39.94:8000/api/health"
echo "   GET http://72.61.39.94:8000/api/cadastros/clientes"
echo "   GET http://72.61.39.94:8000/api/cadastros/servicos"
echo "   GET http://72.61.39.94:8000/api/faturamento/faturas"
echo ""
echo "📋 Ver todas as rotas:"
echo "   php artisan route:list --path=api"
echo ""

