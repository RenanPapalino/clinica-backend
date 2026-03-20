<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rag_document_id')->constrained('rag_documents')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('chunk_index')->default(0);
            $table->longText('content');
            $table->string('content_hash', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['rag_document_id', 'version', 'chunk_index']);
            $table->index(['rag_document_id', 'is_active']);
            $table->index(['version', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_chunks');
    }
};
