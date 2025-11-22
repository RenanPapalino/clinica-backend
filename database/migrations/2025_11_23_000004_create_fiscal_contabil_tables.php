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
            // Se a tabela já existe, adicionamos os campos. 
            // Se não, crie um create() completo aqui. Assumindo update:
            
            // Dados Fiscais
            $table->decimal('base_calculo', 15, 2)->nullable();
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->decimal('valor_iss', 15, 2)->nullable();
            $table->boolean('iss_retido')->default(false);
            
            // Retenções Federais (PIS, COFINS, CSLL, IR, INSS)
            $table->decimal('valor_pis', 15, 2)->default(0);
            $table->decimal('valor_cofins', 15, 2)->default(0);
            $table->decimal('valor_csll', 15, 2)->default(0);
            $table->decimal('valor_ir', 15, 2)->default(0);
            $table->decimal('valor_inss', 15, 2)->default(0);
            $table->decimal('valor_liquido', 15, 2)->nullable();

            // Controle de Emissão
            $table->string('codigo_verificacao')->nullable();
            $table->dateTime('data_competencia')->nullable();
            $table->string('xml_url')->nullable();
            $table->text('motivo_cancelamento')->nullable();
        });

        // 2. Lançamentos Contábeis (O "Livro Diário")
        Schema::create('lancamentos_contabeis', function (Blueprint $table) {
            $table->id();
            $table->date('data_lancamento');
            $table->string('historico');
            
            // Partidas Dobradas (De/Para)
            $table->foreignId('conta_debito_id')->constrained('planos_contas');
            $table->foreignId('conta_credito_id')->constrained('planos_contas');
            $table->decimal('valor', 15, 2);
            
            // Origem do lançamento (para rastreabilidade / Audit)
            $table->string('origem_tipo')->nullable(); // Ex: App\Models\Nfse
            $table->unsignedBigInteger('origem_id')->nullable();
            
            $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo');
            $table->timestamps();
        });

        // 3. Guias de Impostos (O "Contas a Pagar" do Governo)
        Schema::create('guias_impostos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo'); // DAS, DARF_IR, DARF_PIS, GPS
            $table->date('periodo_apuracao'); // 2025-10-01
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

    public function down()
    {
        Schema::dropIfExists('guias_impostos');
        Schema::dropIfExists('lancamentos_contabeis');
        Schema::table('nfse', function (Blueprint $table) {
            $table->dropColumn(['base_calculo', 'valor_pis', 'valor_cofins', 'codigo_verificacao', 'xml_url']);
        });
    }
};