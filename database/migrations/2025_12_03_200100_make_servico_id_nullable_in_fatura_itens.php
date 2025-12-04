<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fatura_itens', 'servico_id')) {
            DB::statement('ALTER TABLE `fatura_itens` MODIFY `servico_id` BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fatura_itens', 'servico_id')) {
            DB::statement('ALTER TABLE `fatura_itens` MODIFY `servico_id` BIGINT UNSIGNED NOT NULL');
        }
    }
};
