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
            $table->unsignedInteger('revision_number')->default(1)->after('version_label');
            $table->string('content_hash', 64)->nullable()->after('revision_number');

            $table->index(['deployment_id', 'content_hash']);
        });

        $documents = DB::table('knowledge_documents')
            ->select('id', 'supersedes_document_id')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        foreach ($documents as $document) {
            if ($document->supersedes_document_id === null) {
                continue;
            }

            $revisionNumber = $this->resolveRevisionNumber((int) $document->id, $documents);

            DB::table('knowledge_documents')
                ->where('id', $document->id)
                ->update(['revision_number' => $revisionNumber]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex(['deployment_id', 'content_hash']);
            $table->dropColumn(['revision_number', 'content_hash']);
        });
    }

    /**
     * @param  Collection<int, object{id: int, supersedes_document_id: int|null}>  $documents
     */
    private function resolveRevisionNumber(int $documentId, $documents): int
    {
        $revisionNumber = 1;
        $currentId = $documentId;
        $visited = [];

        while (true) {
            $document = $documents->get($currentId);

            if ($document === null || $document->supersedes_document_id === null) {
                break;
            }

            if (in_array($currentId, $visited, true)) {
                break;
            }

            $visited[] = $currentId;
            $revisionNumber++;
            $currentId = (int) $document->supersedes_document_id;
        }

        return $revisionNumber;
    }
};
