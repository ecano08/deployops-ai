<?php

namespace App\Services;

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class KnowledgeDocumentLifecycleService
{
    public function activate(KnowledgeDocument $document): KnowledgeDocument
    {
        if ($document->status !== KnowledgeDocumentStatus::Ready) {
            throw new InvalidArgumentException('Only ready documents can be activated.');
        }

        if ($document->lifecycle_status !== KnowledgeDocumentLifecycleStatus::Draft) {
            throw new InvalidArgumentException('Only draft documents can be activated.');
        }

        return DB::transaction(function () use ($document): KnowledgeDocument {
            $document = $document->fresh();

            if ($document === null) {
                throw new InvalidArgumentException('Document no longer exists.');
            }

            $familyIds = $document->versionFamilyIds();

            KnowledgeDocument::query()
                ->whereIn('id', $familyIds)
                ->where('id', '!=', $document->id)
                ->where('lifecycle_status', KnowledgeDocumentLifecycleStatus::Active)
                ->update(['lifecycle_status' => KnowledgeDocumentLifecycleStatus::Superseded]);

            $document->update([
                'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Active,
            ]);

            return $document->fresh() ?? $document;
        });
    }

    public function archive(KnowledgeDocument $document): KnowledgeDocument
    {
        if ($document->lifecycle_status === KnowledgeDocumentLifecycleStatus::Archived) {
            return $document;
        }

        $document->update([
            'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Archived,
        ]);

        return $document->fresh() ?? $document;
    }
}
