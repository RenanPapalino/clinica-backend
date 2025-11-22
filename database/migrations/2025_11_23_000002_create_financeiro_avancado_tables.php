<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. PLANO DE CONTAS (Baseado no Manual "Cadastro de Plano de Contas")
        Schema::create('planos_contas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->index(); // Ex: "2.1.03"
            $table->string('descricao'); // Ex: "Despesas com Pessoal"
            $table->enum('tipo', ['receita', 'despesa']); 
            $table->enum('natureza', ['fixa', 'variavel'])->default('variavel'); // Manual pede Fixo/Variável
            $table->string('conta_contabil')->nullable(); // Integração Contábil
            $table->boolean('analitica')->default(true); // True = Aceita lançamentos, False = É apenas grupo (pai)
            $table->boolean('inibir_relatorios')->default(false); // Manual pede "Inibir nos relatórios"
            $table->foreignId('conta_pai_id')->nullable()->constrained('planos_contas')->nullOnDelete();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        // 2. CENTROS DE CUSTO (Departamentos/Projetos)
        Schema::create('centros_custo', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('codigo')->nullable();
            $table->enum('tipo', ['departamento', 'projeto', 'unidade'])->default('departamento');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        // 3. ATUALIZAÇÃO DE CLIENTES (Baseado no Manual "Cadastro de Empresas")
        // Adicionando os campos fiscais e detalhados que o manual exige
        Schema::table('clientes', function (Blueprint $table) {
            // Dados Fiscais / Pessoa Jurídica
            $table->string('nome_fantasia')->nullable()->change(); // Garantir que existe
            $table->string('inscricao_municipal')->nullable();
            $table->string('inscricao_estadual')->nullable();
            $table->string('inscricao_suframa')->nullable(); // Manual pag. 4
            $table->string('cnae')->nullable();
            $table->string('cei')->nullable(); // Manual pag. 4
            
            // Configurações de Retenção (Crucial para o Faturamento)
            $table->boolean('simples_nacional')->default(false);
            $table->boolean('reter_iss')->default(false);
            $table->boolean('reter_pcc')->default(false); // PIS/COFINS/CSLL
            $table->boolean('reter_inss')->default(false);
            $table->boolean('reter_ir')->default(false);
            $table->decimal('aliquota_iss', 5, 2)->nullable(); // Ex: 2.00 a 5.00
            
            // Dados de Cobrança
            $table->boolean('protestar')->default(false);
            $table->integer('dias_protesto')->nullable();
            $table->foreignId('plano_conta_padrao_id')->nullable()->constrained('planos_contas');
            $table->foreignId('centro_custo_padrao_id')->nullable()->constrained('centros_custo');
        });

        // 4. FORNECEDORES (Espelho de Clientes, mas para Contas a Pagar)
        // Se a tabela já existir na migration anterior, isso será ignorado ou pode ser um "create" se não existir
        if (!Schema::hasTable('fornecedores')) {
            Schema::create('fornecedores', function (Blueprint $table) {
                $table->id();
                $table->string('razao_social');
                $table->string('nome_fantasia')->nullable();
                $table->string('cnpj')->unique()->nullable();
                $table->string('cpf')->unique()->nullable();
                $table->string('email')->nullable();
                $table->string('telefone')->nullable();
                
                // Dados Bancários (Para gerar remessa de pagamento)
                $table->string('banco_nome')->nullable();
                $table->string('agencia')->nullable();
                $table->string('conta')->nullable();
                $table->string('ispb')->nullable(); // Manual pag. 4
                
                $table->enum('status', ['ativo', 'inativo'])->default('ativo');
                $table->timestamps();
            });
        }

        // 5. RATEIOS (A tabela que liga Títulos a Múltiplos Centros de Custo/Planos)
        // Baseado no Manual "Processos do Contas a Pagar - Rateio"
        Schema::create('titulo_rateios', function (Blueprint $table) {
            $table->id();
            // Polimórfico: Pode ser rateio de 'Titulo' (Pagar/Receber) ou 'Fatura'
            $table->foreignId('titulo_id')->constrained('titulos')->onDelete('cascade'); 
            
            $table->foreignId('plano_conta_id')->constrained('planos_contas');
            $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo');
            
            $table->decimal('valor', 15, 2);
            $table->decimal('percentual', 5, 2)->nullable(); // Ex: 30%
            $table->string('historico')->nullable(); // Descrição específica deste rateio
            
            $table->timestamps();
        });
        
        // 6. Atualizar TITULOS para suportar vínculo com Plano de Contas (cabeçalho)
        Schema::table('titulos', function (Blueprint $table) {
            $table->foreignId('fornecedor_id')->nullable()->constrained('fornecedores'); // Para Contas a Pagar
            $table->foreignId('plano_conta_id')->nullable()->constrained('planos_contas'); // Classificação principal
            $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo'); // Classificação principal
            $table->string('nosso_numero')->nullable(); // Para boleto bancário
            $table->string('documento_banco')->nullable(); // Manual pag. 28
            $table->date('competencia')->nullable(); // Para DRE por competência
        });
    }

    public function down()
    {
        Schema::dropIfExists('titulo_rateios');
        Schema::table('titulos', function (Blueprint $table) {
            $table->dropForeign(['fornecedor_id']);
            $table->dropForeign(['plano_conta_id']);
            $table->dropForeign(['centro_custo_id']);
            $table->dropColumn(['fornecedor_id', 'plano_conta_id', 'centro_custo_id', 'nosso_numero', 'documento_banco', 'competencia']);
        });
        Schema::table('clientes', function (Blueprint $table) {
            // Remover colunas adicionadas (simplificado)
            $table->dropColumn(['inscricao_municipal', 'inscricao_estadual', 'reter_iss', 'reter_pcc']);
        });
        Schema::dropIfExists('centros_custo');
        Schema::dropIfExists('planos_contas');
        // Nota: Não dropamos fornecedores se ela for crítica, mas em dev pode dropar.
    }
};