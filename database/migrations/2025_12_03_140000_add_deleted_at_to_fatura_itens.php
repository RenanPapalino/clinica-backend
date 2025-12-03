<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fatura_itens', function (Blueprint $table) {
            if (!Schema::hasColumn('fatura_itens', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fatura_itens', function (Blueprint $table) {
            if (Schema::hasColumn('fatura_itens', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
        });
    }
};
