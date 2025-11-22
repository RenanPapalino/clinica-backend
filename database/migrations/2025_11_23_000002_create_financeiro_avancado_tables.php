<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. PLANO DE CONTAS (Cria se não existir)
        if (!Schema::hasTable('planos_contas')) {
            Schema::create('planos_contas', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 20)->index();
                $table->string('descricao');
                $table->enum('tipo', ['receita', 'despesa']); 
                $table->enum('natureza', ['fixa', 'variavel'])->default('variavel');
                $table->string('conta_contabil')->nullable();
                $table->boolean('analitica')->default(true);
                $table->boolean('inibir_relatorios')->default(false);
                $table->foreignId('conta_pai_id')->nullable()->constrained('planos_contas')->nullOnDelete();
                $table->boolean('ativo')->default(true);
                $table->timestamps();
            });
        }

        // 2. CENTROS DE CUSTO (Cria se não existir)
        if (!Schema::hasTable('centros_custo')) {
            Schema::create('centros_custo', function (Blueprint $table) {
                $table->id();
                $table->string('nome');
                $table->string('codigo')->nullable();
                $table->enum('tipo', ['departamento', 'projeto', 'unidade'])->default('departamento');
                $table->boolean('ativo')->default(true);
                $table->timestamps();
            });
        }

        // 3. ATUALIZAÇÃO DE CLIENTES (Verifica coluna por coluna)
        Schema::table('clientes', function (Blueprint $table) {
            // Dados Fiscais
            if (!Schema::hasColumn('clientes', 'nome_fantasia')) {
                $table->string('nome_fantasia')->nullable();
            } else {
                $table->string('nome_fantasia')->nullable()->change();
            }

            if (!Schema::hasColumn('clientes', 'inscricao_municipal')) {
                $table->string('inscricao_municipal')->nullable();
            }
            if (!Schema::hasColumn('clientes', 'inscricao_estadual')) {
                $table->string('inscricao_estadual')->nullable();
            }
            if (!Schema::hasColumn('clientes', 'inscricao_suframa')) {
                $table->string('inscricao_suframa')->nullable();
            }
            if (!Schema::hasColumn('clientes', 'cnae')) {
                $table->string('cnae')->nullable();
            }
            if (!Schema::hasColumn('clientes', 'cei')) {
                $table->string('cei')->nullable();
            }
            
            // Configurações de Retenção
            if (!Schema::hasColumn('clientes', 'simples_nacional')) {
                $table->boolean('simples_nacional')->default(false);
            }
            if (!Schema::hasColumn('clientes', 'reter_iss')) {
                $table->boolean('reter_iss')->default(false);
            }
            if (!Schema::hasColumn('clientes', 'reter_pcc')) {
                $table->boolean('reter_pcc')->default(false);
            }
            if (!Schema::hasColumn('clientes', 'reter_inss')) {
                $table->boolean('reter_inss')->default(false);
            }
            if (!Schema::hasColumn('clientes', 'reter_ir')) {
                $table->boolean('reter_ir')->default(false);
            }
            if (!Schema::hasColumn('clientes', 'aliquota_iss')) {
                $table->decimal('aliquota_iss', 5, 2)->nullable();
            }
            
            // Dados de Cobrança
            if (!Schema::hasColumn('clientes', 'protestar')) {
                $table->boolean('protestar')->default(false);
            }
            if (!Schema::hasColumn('clientes', 'dias_protesto')) {
                $table->integer('dias_protesto')->nullable();
            }
            
            // Chaves Estrangeiras (Verifica se já existem antes de criar)
            if (Schema::hasTable('planos_contas') && !Schema::hasColumn('clientes', 'plano_conta_padrao_id')) {
                $table->foreignId('plano_conta_padrao_id')->nullable()->constrained('planos_contas');
            }
            if (Schema::hasTable('centros_custo') && !Schema::hasColumn('clientes', 'centro_custo_padrao_id')) {
                $table->foreignId('centro_custo_padrao_id')->nullable()->constrained('centros_custo');
            }
        });

        // 4. FORNECEDORES
        if (!Schema::hasTable('fornecedores')) {
            Schema::create('fornecedores', function (Blueprint $table) {
                $table->id();
                $table->string('razao_social');
                $table->string('nome_fantasia')->nullable();
                $table->string('cnpj')->unique()->nullable();
                $table->string('cpf')->unique()->nullable();
                $table->string('email')->nullable();
                $table->string('telefone')->nullable();
                
                $table->string('banco_nome')->nullable();
                $table->string('agencia')->nullable();
                $table->string('conta')->nullable();
                $table->string('ispb')->nullable();
                
                $table->enum('status', ['ativo', 'inativo'])->default('ativo');
                $table->timestamps();
            });
        }

        // 5. RATEIOS
        if (!Schema::hasTable('titulo_rateios')) {
            Schema::create('titulo_rateios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('titulo_id')->constrained('titulos')->onDelete('cascade'); 
                $table->foreignId('plano_conta_id')->constrained('planos_contas');
                $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo');
                $table->decimal('valor', 15, 2);
                $table->decimal('percentual', 5, 2)->nullable();
                $table->string('historico')->nullable();
                $table->timestamps();
            });
        }
        
        // 6. Atualizar TITULOS
        Schema::table('titulos', function (Blueprint $table) {
            if (!Schema::hasColumn('titulos', 'fornecedor_id')) {
                $table->foreignId('fornecedor_id')->nullable()->constrained('fornecedores');
            }
            if (!Schema::hasColumn('titulos', 'plano_conta_id')) {
                $table->foreignId('plano_conta_id')->nullable()->constrained('planos_contas');
            }
            if (!Schema::hasColumn('titulos', 'centro_custo_id')) {
                $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo');
            }
            if (!Schema::hasColumn('titulos', 'nosso_numero')) {
                $table->string('nosso_numero')->nullable();
            }
            if (!Schema::hasColumn('titulos', 'documento_banco')) {
                $table->string('documento_banco')->nullable();
            }
            if (!Schema::hasColumn('titulos', 'competencia')) {
                $table->date('competencia')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('titulo_rateios');
        // (Opcional) Drop columns...
        // Schema::dropIfExists('fornecedores');
        // Schema::dropIfExists('centros_custo');
        // Schema::dropIfExists('planos_contas');
    }
};