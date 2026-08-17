<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class KnowledgeDocumentVersionService
{
    public function computeContentHash(UploadedFile $file): string
    {
        $hash = hash_file('sha256', $file->getRealPath() ?: $file->getPathname());

        if ($hash === false) {
            throw new InvalidArgumentException('Unable to compute a content hash for the uploaded file.');
        }

        return $hash;
    }

    public function normalizeDocumentName(string $value): string
    {
        $name = pathinfo($value, PATHINFO_FILENAME);
        $normalized = strtolower($name);
        $normalized = preg_replace('/[\s_\-]+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    public function titleFromFilename(string $filename): string
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $title = str_replace(['_', '-'], ' ', $basename);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return trim($title);
    }

    public function findDuplicateByHash(Deployment $deployment, string $contentHash): ?KnowledgeDocument
    {
        return KnowledgeDocument::query()
            ->where('deployment_id', $deployment->id)
            ->where('content_hash', $contentHash)
            ->first();
    }

    /**
     * @return Collection<int, KnowledgeDocument>
     */
    public function findLikelyMatches(Deployment $deployment, string $filename, ?string $title = null): Collection
    {
        $normalizedFilename = $this->normalizeDocumentName($filename);
        $normalizedTitle = $title !== null && trim($title) !== ''
            ? $this->normalizeDocumentName($title)
            : $normalizedFilename;

        if ($normalizedFilename === '' && $normalizedTitle === '') {
            return collect();
        }

        return KnowledgeDocument::query()
            ->where('deployment_id', $deployment->id)
            ->get()
            ->filter(function (KnowledgeDocument $document) use ($normalizedFilename, $normalizedTitle): bool {
                $documentTitle = $this->normalizeDocumentName($document->title ?? '');
                $documentFilename = $this->normalizeDocumentName($document->original_filename);

                return $documentTitle === $normalizedTitle
                    || $documentTitle === $normalizedFilename
                    || $documentFilename === $normalizedFilename
                    || $documentFilename === $normalizedTitle;
            })
            ->sortByDesc('revision_number')
            ->values();
    }

    public function resolveChainHead(KnowledgeDocument $document): KnowledgeDocument
    {
        $familyIds = $document->versionFamilyIds();

        return KnowledgeDocument::query()
            ->whereIn('id', $familyIds)
            ->orderByDesc('revision_number')
            ->orderByDesc('id')
            ->first() ?? $document;
    }

    public function resolveRevisionNumber(?KnowledgeDocument $supersedesDocument): int
    {
        if ($supersedesDocument === null) {
            return 1;
        }

        $familyIds = $supersedesDocument->versionFamilyIds();

        $maxRevision = KnowledgeDocument::query()
            ->whereIn('id', $familyIds)
            ->max('revision_number');

        return ((int) $maxRevision) + 1;
    }

    public function assertNotDuplicate(Deployment $deployment, string $contentHash): void
    {
        $duplicate = $this->findDuplicateByHash($deployment, $contentHash);

        if ($duplicate === null) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'This file is identical to revision %d of "%s". Upload changed content or delete the duplicate first.',
            $duplicate->revision_number,
            $duplicate->title,
        ));
    }
}
