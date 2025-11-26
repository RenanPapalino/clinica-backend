<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lancamentos_contabeis', function (Blueprint $table) {
            $table->string('status_ia')->default('manual'); // manual|sugerido|aprovado|revisar
            $table->decimal('score_ia', 5, 2)->nullable();
            $table->json('sugestao_ia')->nullable(); // debito/credito sugeridos, motivo etc
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos_contabeis', function (Blueprint $table) {
            $table->dropColumn(['status_ia', 'score_ia', 'sugestao_ia']);
        });
    }
};
