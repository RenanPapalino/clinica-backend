<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clientes')) {
            return;
        }

        $hadEndereco = Schema::hasColumn('clientes', 'endereco');
        $hadLogradouro = Schema::hasColumn('clientes', 'logradouro');

        Schema::table('clientes', function (Blueprint $table) {
            if (!Schema::hasColumn('clientes', 'site')) {
                $table->string('site')->nullable();
            }
            if (!Schema::hasColumn('clientes', 'logradouro')) {
                $table->string('logradouro')->nullable();
            }
            if (!Schema::hasColumn('clientes', 'complemento')) {
                $table->string('complemento')->nullable();
            }
            if (!Schema::hasColumn('clientes', 'cidade')) {
                $table->string('cidade')->nullable();
            }
            if (!Schema::hasColumn('clientes', 'uf')) {
                $table->string('uf', 2)->nullable();
            }
            if (!Schema::hasColumn('clientes', 'status')) {
                $table->string('status', 10)->default('ativo');
            }
            if (!Schema::hasColumn('clientes', 'aliquota_iss')) {
                $table->decimal('aliquota_iss', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('clientes', 'prazo_pagamento_dias')) {
                $table->integer('prazo_pagamento_dias')->nullable();
            }
            if (!Schema::hasColumn('clientes', 'observacoes')) {
                $table->text('observacoes')->nullable();
            }
        });

        if ($hadEndereco && !$hadLogradouro && Schema::hasColumn('clientes', 'logradouro')) {
            DB::statement("
                UPDATE clientes
                SET logradouro = endereco
                WHERE (logradouro IS NULL OR logradouro = '')
                  AND endereco IS NOT NULL
                  AND endereco <> ''
            ");
        }
    }

    public function down(): void
    {
        // Migration defensiva: não remove colunas no down para evitar perda de dados.
    }
};
