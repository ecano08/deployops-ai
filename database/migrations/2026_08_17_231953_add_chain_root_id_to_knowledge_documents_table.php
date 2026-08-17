<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
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
            $table->foreignId('chain_root_id')
                ->nullable()
                ->after('supersedes_document_id')
                ->constrained('knowledge_documents')
                ->nullOnDelete();

            $table->index(['deployment_id', 'chain_root_id']);
        });

        $documents = DB::table('knowledge_documents')
            ->select('id', 'supersedes_document_id')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        foreach ($documents as $document) {
            $chainRootId = $this->resolveChainRootId((int) $document->id, $documents);

            DB::table('knowledge_documents')
                ->where('id', $document->id)
                ->update(['chain_root_id' => $chainRootId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropForeign(['chain_root_id']);
            $table->dropIndex(['deployment_id', 'chain_root_id']);
            $table->dropColumn('chain_root_id');
        });
    }

    /**
     * @param  Collection<int, object{id: int, supersedes_document_id: int|null}>  $documents
     */
    private function resolveChainRootId(int $documentId, Collection $documents): int
    {
        $currentId = $documentId;
        $visited = [];

        while (true) {
            $document = $documents->get($currentId);

            if ($document === null || $document->supersedes_document_id === null) {
                return $currentId;
            }

            if (in_array($currentId, $visited, true)) {
                return $currentId;
            }

            $visited[] = $currentId;
            $currentId = (int) $document->supersedes_document_id;
        }
    }
};
