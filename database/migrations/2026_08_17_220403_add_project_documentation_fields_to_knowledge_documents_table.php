<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('title')->nullable()->after('uploaded_by');
            $table->string('document_type')->default('other')->after('title');
            $table->string('version_label')->nullable()->after('document_type');
            $table->string('lifecycle_status')->default('draft')->after('version_label');
            $table->timestamp('effective_at')->nullable()->after('lifecycle_status');
            $table->foreignId('supersedes_document_id')
                ->nullable()
                ->after('effective_at')
                ->constrained('knowledge_documents')
                ->nullOnDelete();
            $table->json('metadata')->nullable()->after('supersedes_document_id');

            $table->index(['deployment_id', 'lifecycle_status']);
        });

        DB::table('knowledge_documents')->update([
            'title' => DB::raw('original_filename'),
            'document_type' => 'other',
            'lifecycle_status' => 'active',
        ]);

        DB::table('knowledge_documents')
            ->whereNull('title')
            ->update(['title' => DB::raw('original_filename')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropForeign(['supersedes_document_id']);
            $table->dropIndex(['deployment_id', 'lifecycle_status']);
            $table->dropColumn([
                'title',
                'document_type',
                'version_label',
                'lifecycle_status',
                'effective_at',
                'supersedes_document_id',
                'metadata',
            ]);
        });
    }
};
