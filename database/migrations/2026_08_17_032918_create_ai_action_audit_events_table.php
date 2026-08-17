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
        Schema::create('ai_action_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_proposed_action_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ai_proposed_action_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_action_audit_events');
    }
};
