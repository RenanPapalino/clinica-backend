<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            if (!Schema::hasColumn('faturas', 'nfse_numero')) {
                $table->string('nfse_numero', 100)->nullable()->after('nfse_emitida');
            }

            if (!Schema::hasColumn('faturas', 'nfse_link')) {
                $table->string('nfse_link')->nullable()->after('nfse_numero');
            }
        });
    }

    public function down(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            if (Schema::hasColumn('faturas', 'nfse_link')) {
                $table->dropColumn('nfse_link');
            }

            if (Schema::hasColumn('faturas', 'nfse_numero')) {
                $table->dropColumn('nfse_numero');
            }
        });
    }
};
