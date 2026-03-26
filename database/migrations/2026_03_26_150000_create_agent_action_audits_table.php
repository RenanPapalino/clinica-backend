<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_action_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('target_type', 80);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label')->nullable();
            $table->string('confirmation_strength', 40)->nullable();
            $table->string('confirmation_phrase')->nullable();
            $table->text('confirmation_message')->nullable();
            $table->string('confirmation_source', 80)->nullable();
            $table->string('runtime_pending_action_id')->nullable();
            $table->string('session_id')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['action', 'executed_at']);
            $table->index(['target_type', 'target_id']);
            $table->index(['user_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_action_audits');
    }
};
