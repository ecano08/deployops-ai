<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_tool_call_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('copilot_request_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('tool_name');
            $table->unsignedInteger('duration_ms');
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['deployment_id', 'created_at']);
            $table->index(['tool_name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_tool_call_traces');
    }
};
