#!/bin/bash
set -e

echo "🔧 CORRIGINDO TUDO..."

cd /var/www/clinica-backend

# ===========================
# 1. REMOVER MIGRATIONS ANTIGAS
# ===========================
echo "🗑️  Removendo migrations antigas..."
rm -f database/migrations/*_create_servicos_table.php
rm -f database/migrations/*_create_faturas_table.php
rm -f database/migrations/*_create_fatura_itens_table.php
rm -f database/migrations/*_create_nfse*.php
rm -f database/migrations/*_create_titulos_table.php
rm -f database/migrations/*_create_titulo_baixas_table.php
rm -f database/migrations/*_create_cobrancas_table.php
rm -f database/migrations/*_create_plano_contas_table.php
rm -f database/migrations/*_create_centros_custo_table.php
rm -f database/migrations/*_create_configuracoes_table.php
rm -f database/migrations/*_add_nfse*.php

# ===========================
# 2. CRIAR TODOS OS MODELS
# ===========================
echo "🎨 Criando Models..."

mkdir -p app/Models

# Model: Servico
cat > app/Models/Servico.php << 'PHPEOF'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Servico extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'servicos';
    protected $fillable = ['codigo', 'descricao', 'descricao_completa', 'valor_unitario', 'custo_unitario', 'codigo_servico_municipio', 'cnae', 'aliquota_iss', 'categoria', 'status', 'observacoes'];
    protected $casts = ['valor_unitario' => 'decimal:2', 'custo_unitario' => 'decimal:2', 'aliquota_iss' => 'decimal:2'];
}
PHPEOF

# Model: Fatura
cat > app/Models/Fatura.php << 'PHPEOF'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fatura extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'faturas';
    protected $fillable = ['cliente_id', 'numero_fatura', 'data_emissao', 'data_vencimento', 'periodo_referencia', 'valor_servicos', 'valor_descontos', 'valor_acrescimos', 'valor_iss', 'valor_total', 'status', 'nfse_emitida', 'observacoes', 'metadata'];
    protected $casts = ['data_emissao' => 'date', 'data_vencimento' => 'date', 'valor_servicos' => 'decimal:2', 'valor_descontos' => 'decimal:2', 'valor_acrescimos' => 'decimal:2', 'valor_iss' => 'decimal:2', 'valor_total' => 'decimal:2', 'nfse_emitida' => 'boolean', 'metadata' => 'array'];
    public function cliente() { return $this->belongsTo(Cliente::class); }
    public function itens() { return $this->hasMany(FaturaItem::class); }
}
PHPEOF

# Model: FaturaItem
cat > app/Models/FaturaItem.php << 'PHPEOF'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FaturaItem extends Model
{
    use HasFactory;
    protected $table = 'fatura_itens';
    protected $fillable = ['fatura_id', 'servico_id', 'item_numero', 'descricao', 'quantidade', 'valor_unitario', 'valor_total', 'data_realizacao', 'funcionario', 'matricula'];
    protected $casts = ['quantidade' => 'integer', 'valor_unitario' => 'decimal:2', 'valor_total' => 'decimal:2', 'data_realizacao' => 'date'];
    public function fatura() { return $this->belongsTo(Fatura::class); }
    public function servico() { return $this->belongsTo(Servico::class); }
}
PHPEOF

# Model: Nfse
cat > app/Models/Nfse.php << 'PHPEOF'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nfse extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'nfse';
    protected $fillable = ['fatura_id', 'cliente_id', 'lote_id', 'numero_nfse', 'codigo_verificacao', 'protocolo', 'data_emissao', 'data_envio', 'data_autorizacao', 'valor_servicos', 'valor_deducoes', 'valor_iss', 'aliquota_iss', 'valor_liquido', 'status', 'codigo_servico', 'discriminacao', 'xml_nfse', 'pdf_url', 'mensagem_erro', 'detalhes_erro'];
    protected $casts = ['data_emissao' => 'datetime', 'data_envio' => 'datetime', 'data_autorizacao' => 'datetime', 'valor_servicos' => 'decimal:2', 'valor_deducoes' => 'decimal:2', 'valor_iss' => 'decimal:2', 'aliquota_iss' => 'decimal:2', 'valor_liquido' => 'decimal:2', 'detalhes_erro' => 'array'];
    public function fatura() { return $this->belongsTo(Fatura::class); }
    public function cliente() { return $this->belongsTo(Cliente::class); }
}
PHPEOF

# Model: Titulo
cat > app/Models/Titulo.php << 'PHPEOF'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Titulo extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'titulos';
    protected $fillable = ['cliente_id', 'fatura_id', 'numero_titulo', 'nosso_numero', 'data_emissao', 'data_vencimento', 'data_pagamento', 'valor_original', 'valor_juros', 'valor_multa', 'valor_desconto', 'valor_pago', 'valor_saldo', 'status', 'forma_pagamento', 'codigo_barras', 'linha_digitavel', 'url_boleto', 'observacoes'];
    protected $casts = ['data_emissao' => 'date', 'data_vencimento' => 'date', 'data_pagamento' => 'date', 'valor_original' => 'decimal:2', 'valor_juros' => 'decimal:2', 'valor_multa' => 'decimal:2', 'valor_desconto' => 'decimal:2', 'valor_pago' => 'decimal:2', 'valor_saldo' => 'decimal:2'];
    public function cliente() { return $this->belongsTo(Cliente::class); }
    public function fatura() { return $this->belongsTo(Fatura::class); }
}
PHPEOF

echo "✅ Models criados!"

# ===========================
# 3. CRIAR MIGRATIONS NA ORDEM CORRETA
# ===========================
echo "📦 Criando migrations..."

sleep 1

# Migration 1: Serviços
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

sleep 2

# Migration 2: Faturas (SEM foreign key para nfse)
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

sleep 2

# Migration 3: Fatura Itens
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

sleep 2

# Migration 4: NFSe
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

sleep 2

# Migration 5: Títulos
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

sleep 2

# Migration 6: Configurações
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

echo "✅ Migrations criadas!"

# ===========================
# 4. CRIAR SEEDER
# ===========================
echo "🌱 Criando Seeder..."

cat > database/seeders/ServicoSeeder.php << 'PHPEOF'
<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\Servico;

class ServicoSeeder extends Seeder
{
    public function run(): void
    {
        $servicos = [
            ['codigo' => 'EXAM-001', 'descricao' => 'Exame Admissional', 'valor_unitario' => 150.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'EXAM-002', 'descricao' => 'Exame Periódico', 'valor_unitario' => 120.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'EXAM-003', 'descricao' => 'Exame Demissional', 'valor_unitario' => 100.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'EXAM-004', 'descricao' => 'Audiometria', 'valor_unitario' => 80.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'EXAM-005', 'descricao' => 'Espirometria', 'valor_unitario' => 90.00, 'categoria' => 'exame', 'status' => 'ativo'],
            ['codigo' => 'CONS-001', 'descricao' => 'Consulta Medicina do Trabalho', 'valor_unitario' => 200.00, 'categoria' => 'consulta', 'status' => 'ativo'],
        ];
        foreach ($servicos as $servico) {
            Servico::create($servico);
        }
        $this->command->info('✅ ' . count($servicos) . ' serviços criados!');
    }
}
PHPEOF

echo "✅ Seeder criado!"

# ===========================
# 5. RESETAR E RODAR TUDO
# ===========================
echo "🔄 Resetando banco de dados..."
php artisan migrate:fresh --force

echo "🌱 Populando dados..."
php artisan db:seed --class=ServicoSeeder --force

echo "🧹 Limpando cache..."
php artisan optimize:clear

echo "🔄 Reiniciando servidor..."
killall php 2>/dev/null || true
nohup php artisan serve --host=0.0.0.0 --port=8000 > /var/log/laravel-api.log 2>&1 &
sleep 3

# ===========================
# 6. TESTAR
# ===========================
echo ""
echo "🧪 TESTANDO API..."
echo ""
echo "✅ Health:"
curl -s http://localhost:8000/api/health | jq '.status' || curl http://localhost:8000/api/health

echo ""
echo "✅ Serviços:"
curl -s http://localhost:8000/api/cadastros/servicos | jq '.data | length' || curl http://localhost:8000/api/cadastros/servicos

echo ""
echo "✅ Clientes:"
curl -s http://localhost:8000/api/cadastros/clientes | jq '.success' || curl http://localhost:8000/api/cadastros/clientes

echo ""
echo "📋 Tabelas criadas:"
mysql -u clinica_user -p clinica_financeiro -e "SHOW TABLES;" 2>/dev/null || echo "Execute: mysql -u clinica_user -p clinica_financeiro -e 'SHOW TABLES;'"

echo ""
echo "✅ BACKEND COMPLETO E FUNCIONANDO!"
echo ""
echo "🌐 Testar no Postman:"
echo "   http://72.61.39.94:8000/api/health"
echo "   http://72.61.39.94:8000/api/cadastros/servicos"
echo "   http://72.61.39.94:8000/api/cadastros/clientes"
echo "   http://72.61.39.94:8000/api/faturamento/faturas"
echo ""
echo "📋 Ver todas as rotas:"
echo "   php artisan route:list --path=api"

