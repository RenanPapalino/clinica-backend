<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. Atualizar NFS-e com campos fiscais detalhados
        Schema::table('nfse', function (Blueprint $table) {
            
            // Verifica cada coluna antes de adicionar
            if (!Schema::hasColumn('nfse', 'base_calculo')) {
                $table->decimal('base_calculo', 15, 2)->nullable();
            }
            
            if (!Schema::hasColumn('nfse', 'aliquota_iss')) {
                $table->decimal('aliquota_iss', 5, 2)->nullable();
            }

            if (!Schema::hasColumn('nfse', 'valor_iss')) {
                $table->decimal('valor_iss', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('nfse', 'iss_retido')) {
                $table->boolean('iss_retido')->default(false);
            }
            
            // Retenções Federais
            if (!Schema::hasColumn('nfse', 'valor_pis')) {
                $table->decimal('valor_pis', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('nfse', 'valor_cofins')) {
                $table->decimal('valor_cofins', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('nfse', 'valor_csll')) {
                $table->decimal('valor_csll', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('nfse', 'valor_ir')) {
                $table->decimal('valor_ir', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('nfse', 'valor_inss')) {
                $table->decimal('valor_inss', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('nfse', 'valor_liquido')) {
                $table->decimal('valor_liquido', 15, 2)->nullable();
            }

            // Controle de Emissão
            if (!Schema::hasColumn('nfse', 'codigo_verificacao')) {
                $table->string('codigo_verificacao')->nullable();
            }
            if (!Schema::hasColumn('nfse', 'data_competencia')) {
                $table->dateTime('data_competencia')->nullable();
            }
            if (!Schema::hasColumn('nfse', 'xml_url')) {
                $table->string('xml_url')->nullable();
            }
            if (!Schema::hasColumn('nfse', 'motivo_cancelamento')) {
                $table->text('motivo_cancelamento')->nullable();
            }
        });

        // 2. Lançamentos Contábeis
        if (!Schema::hasTable('lancamentos_contabeis')) {
            Schema::create('lancamentos_contabeis', function (Blueprint $table) {
                $table->id();
                $table->date('data_lancamento');
                $table->string('historico');
                
                // Partidas Dobradas (De/Para)
                $table->foreignId('conta_debito_id')->constrained('planos_contas');
                $table->foreignId('conta_credito_id')->constrained('planos_contas');
                $table->decimal('valor', 15, 2);
                
                // Origem do lançamento
                $table->string('origem_tipo')->nullable();
                $table->unsignedBigInteger('origem_id')->nullable();
                
                $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo');
                $table->timestamps();
            });
        }

        // 3. Guias de Impostos
        if (!Schema::hasTable('guias_impostos')) {
            Schema::create('guias_impostos', function (Blueprint $table) {
                $table->id();
                $table->string('tipo'); // DAS, DARF_IR, DARF_PIS, GPS
                $table->date('periodo_apuracao'); 
                $table->date('vencimento');
                $table->decimal('valor_principal', 15, 2);
                $table->decimal('multa_juros', 15, 2)->default(0);
                $table->decimal('valor_total', 15, 2);
                $table->string('codigo_barras')->nullable();
                $table->enum('status', ['aberto', 'pago'])->default('aberto');
                $table->string('comprovante_url')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('guias_impostos');
        Schema::dropIfExists('lancamentos_contabeis');
        // Schema::table('nfse', function (Blueprint $table) { ... }); // Opcional
    }
};