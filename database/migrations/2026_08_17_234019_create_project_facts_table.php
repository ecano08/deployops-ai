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
        Schema::create('project_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('key');
            $table->text('value');
            $table->foreignId('source_document_id')->nullable()->constrained('knowledge_documents')->nullOnDelete();
            $table->unsignedInteger('source_revision')->nullable();
            $table->text('source_reference')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('status');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('project_facts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('extraction_metadata')->nullable();
            $table->timestamps();

            $table->index(['deployment_id', 'status']);
            $table->index(['deployment_id', 'category', 'key']);
            $table->index(['source_document_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_facts');
    }
};
