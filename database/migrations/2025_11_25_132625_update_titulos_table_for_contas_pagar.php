<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titulos', function (Blueprint $table) {
            
            // 1. Verifica se a coluna 'tipo' já existe antes de adicionar
            if (!Schema::hasColumn('titulos', 'tipo')) {
                $table->enum('tipo', ['pagar', 'receber'])->default('receber')->after('id')->index();
            }
            
            // 2. Verifica se 'fornecedor_id' já existe
            if (!Schema::hasColumn('titulos', 'fornecedor_id')) {
                // Certifique-se que a tabela 'fornecedores' já existe (migration 2025_11_23...)
                $table->foreignId('fornecedor_id')->nullable()->after('cliente_id')->constrained('fornecedores');
            }

            // 3. Altera cliente_id para ser nulo (sempre seguro rodar o change)
            $table->unsignedBigInteger('cliente_id')->nullable()->change();

            // 4. Verifica e adiciona Plano de Contas
            if (!Schema::hasColumn('titulos', 'plano_conta_id')) {
                $table->foreignId('plano_conta_id')->nullable()->constrained('planos_contas');
            }

            // 5. Verifica e adiciona Centro de Custo
            if (!Schema::hasColumn('titulos', 'centro_custo_id')) {
                $table->foreignId('centro_custo_id')->nullable()->constrained('centros_custo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('titulos', function (Blueprint $table) {
            // Remove chaves estrangeiras se existirem
            // Nota: O nome da FK automática geralmente é titulos_nomecoluna_foreign
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes('titulos');
            $foreignKeys = $sm->listTableForeignKeys('titulos');

            if (Schema::hasColumn('titulos', 'fornecedor_id')) {
                // Tenta dropar a FK apenas se ela existir para evitar erros
                try { $table->dropForeign(['fornecedor_id']); } catch (\Exception $e) {}
                $table->dropColumn('fornecedor_id');
            }

            if (Schema::hasColumn('titulos', 'plano_conta_id')) {
                try { $table->dropForeign(['plano_conta_id']); } catch (\Exception $e) {}
                $table->dropColumn('plano_conta_id');
            }

            if (Schema::hasColumn('titulos', 'centro_custo_id')) {
                try { $table->dropForeign(['centro_custo_id']); } catch (\Exception $e) {}
                $table->dropColumn('centro_custo_id');
            }

            if (Schema::hasColumn('titulos', 'tipo')) {
                $table->dropColumn('tipo');
            }
            
            // Reverter cliente_id para não nulo (cuidado se houver dados nulos)
            // $table->unsignedBigInteger('cliente_id')->nullable(false)->change();
        });
    }
};