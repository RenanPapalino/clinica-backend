<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ordem_servico_rateios')) {
            Schema::create('ordem_servico_rateios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ordem_servico_id')->constrained('ordens_servico')->onDelete('cascade');
                $table->string('centro_custo');
                $table->decimal('valor', 15, 2)->default(0);
                $table->decimal('percentual', 6, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ordem_servico_rateios');
    }
};
