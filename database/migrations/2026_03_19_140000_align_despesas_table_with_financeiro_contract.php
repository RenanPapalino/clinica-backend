<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            if (!Schema::hasColumn('despesas', 'valor_original')) {
                $table->decimal('valor_original', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('despesas', 'plano_conta_id')) {
                $table->foreignId('plano_conta_id')->nullable()->constrained('planos_contas')->nullOnDelete();
            }
        });

        if (Schema::hasColumn('despesas', 'valor_original')) {
            DB::table('despesas')
                ->whereNull('valor_original')
                ->update(['valor_original' => DB::raw('valor')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('despesas', 'plano_conta_id')) {
            Schema::table('despesas', function (Blueprint $table) {
                $table->dropConstrainedForeignId('plano_conta_id');
            });
        }

        if (Schema::hasColumn('despesas', 'valor_original')) {
            Schema::table('despesas', function (Blueprint $table) {
                $table->dropColumn('valor_original');
            });
        }
    }
};
