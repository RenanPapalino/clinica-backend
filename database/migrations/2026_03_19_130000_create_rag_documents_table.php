<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 50);
            $table->string('external_id', 191);
            $table->string('file_name');
            $table->string('file_type', 120)->nullable();
            $table->string('business_context', 120)->nullable();
            $table->string('context_key', 120)->nullable();
            $table->string('status', 30)->default('active');
            $table->unsignedInteger('current_version')->default(0);
            $table->unsignedInteger('chunks_count')->default(0);
            $table->string('checksum', 120)->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('last_indexed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['source_system', 'external_id']);
            $table->index(['status', 'business_context']);
            $table->index('context_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_documents');
    }
};
