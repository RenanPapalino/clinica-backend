<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fornecedores')) {
            return;
        }

        Schema::table('fornecedores', function (Blueprint $table) {
            if (!Schema::hasColumn('fornecedores', 'nome_fantasia')) {
                $table->string('nome_fantasia')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'site')) {
                $table->string('site')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'inscricao_estadual')) {
                $table->string('inscricao_estadual')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'inscricao_municipal')) {
                $table->string('inscricao_municipal')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'cep')) {
                $table->string('cep', 10)->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'logradouro')) {
                $table->string('logradouro')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'numero')) {
                $table->string('numero', 20)->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'complemento')) {
                $table->string('complemento')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'bairro')) {
                $table->string('bairro')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'cidade')) {
                $table->string('cidade')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'uf')) {
                $table->string('uf', 2)->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'observacoes')) {
                $table->text('observacoes')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'banco_nome')) {
                $table->string('banco_nome')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'agencia')) {
                $table->string('agencia')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'conta')) {
                $table->string('conta')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'ispb')) {
                $table->string('ispb')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'chave_pix')) {
                $table->string('chave_pix')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'dados_bancarios')) {
                $table->text('dados_bancarios')->nullable();
            }
            if (!Schema::hasColumn('fornecedores', 'reter_iss')) {
                $table->boolean('reter_iss')->default(false);
            }
            if (!Schema::hasColumn('fornecedores', 'reter_pcc')) {
                $table->boolean('reter_pcc')->default(false);
            }
            if (!Schema::hasColumn('fornecedores', 'reter_ir')) {
                $table->boolean('reter_ir')->default(false);
            }
            if (!Schema::hasColumn('fornecedores', 'reter_inss')) {
                $table->boolean('reter_inss')->default(false);
            }
            if (!Schema::hasColumn('fornecedores', 'status')) {
                $table->enum('status', ['ativo', 'inativo'])->default('ativo');
            }
        });
    }

    public function down(): void
    {
        // Migration defensiva: não remove colunas no down para evitar perda de dados.
    }
};
