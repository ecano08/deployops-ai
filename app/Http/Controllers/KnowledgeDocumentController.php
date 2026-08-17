<?php

namespace App\Http\Controllers;

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use App\Http\Requests\ActivateKnowledgeDocumentRequest;
use App\Http\Requests\ArchiveKnowledgeDocumentRequest;
use App\Http\Requests\IndexKnowledgeDocumentsRequest;
use App\Http\Requests\MatchKnowledgeDocumentRequest;
use App\Http\Requests\StoreKnowledgeDocumentRequest;
use App\Http\Resources\KnowledgeDocumentLibraryEntryResource;
use App\Http\Resources\KnowledgeDocumentMatchResource;
use App\Http\Resources\KnowledgeDocumentResource;
use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\Workspace;
use App\Services\AiServiceClient;
use App\Services\KnowledgeDocumentContentService;
use App\Services\KnowledgeDocumentLibraryService;
use App\Services\KnowledgeDocumentLifecycleService;
use App\Services\KnowledgeDocumentVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KnowledgeDocumentController extends Controller
{
    public function index(
        IndexKnowledgeDocumentsRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocumentLibraryService $libraryService,
    ): AnonymousResourceCollection {
        $paginator = $libraryService->paginateLibrary($deployment, $request->validated());

        return KnowledgeDocumentLibraryEntryResource::collection($paginator)
            ->additional([
                'stats' => $libraryService->deploymentStats($deployment),
            ]);
    }

    public function matchCandidates(
        MatchKnowledgeDocumentRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocumentVersionService $versionService,
    ): AnonymousResourceCollection {
        $matches = $versionService->findLikelyMatches(
            $deployment,
            $request->string('filename')->toString(),
            $request->input('title'),
        );

        $candidates = $matches
            ->groupBy(fn (KnowledgeDocument $document): int => min($document->versionFamilyIds()))
            ->map(function ($group) use ($versionService): array {
                /** @var KnowledgeDocument $document */
                $document = $group->first();
                $chainHead = $versionService->resolveChainHead($document);

                return [
                    'document' => $document,
                    'chain_head' => $chainHead,
                ];
            })
            ->values();

        return KnowledgeDocumentMatchResource::collection($candidates);
    }

    public function store(
        StoreKnowledgeDocumentRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocumentVersionService $versionService,
    ): JsonResponse {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $contentHash = $versionService->computeContentHash($file);
            $versionService->assertNotDuplicate($deployment, $contentHash);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'file' => [$exception->getMessage()],
                ],
            ], 422);
        }

        $supersedesDocumentId = $request->integer('supersedes_document_id');
        $supersedesDocumentId = $supersedesDocumentId > 0 ? $supersedesDocumentId : null;

        $supersedesDocument = $supersedesDocumentId !== null
            ? KnowledgeDocument::query()
                ->where('deployment_id', $deployment->id)
                ->find($supersedesDocumentId)
            : null;

        if ($supersedesDocumentId !== null && $supersedesDocument === null) {
            return response()->json([
                'message' => 'The selected document to supersede was not found in this deployment.',
                'errors' => [
                    'supersedes_document_id' => ['The selected document to supersede was not found in this deployment.'],
                ],
            ], 422);
        }

        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            $title = $versionService->titleFromFilename($file->getClientOriginalName());
        }

        $diskPath = $this->storeUploadedFile($workspace, $customer, $deployment, $file);

        $document = KnowledgeDocument::query()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'deployment_id' => $deployment->id,
            'uploaded_by' => $request->user()->id,
            'title' => $title,
            'document_type' => KnowledgeDocumentType::from($request->string('document_type')->toString()),
            'version_label' => $request->input('version_label'),
            'revision_number' => $versionService->resolveRevisionNumber($supersedesDocument),
            'content_hash' => $contentHash,
            'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Draft,
            'effective_at' => $request->input('effective_at'),
            'supersedes_document_id' => $supersedesDocument?->id,
            'chain_root_id' => $supersedesDocument?->chain_root_id,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => (string) $file->getMimeType(),
            'disk_path' => $diskPath,
            'size_bytes' => $file->getSize(),
            'status' => KnowledgeDocumentStatus::Pending,
        ]);

        if ($supersedesDocument === null) {
            $document->update(['chain_root_id' => $document->id]);
        }

        ProcessKnowledgeDocumentJob::dispatch($document);

        return KnowledgeDocumentResource::make(
            $document->load('supersedes:id,title,revision_number'),
        )
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocument $knowledgeDocument,
        KnowledgeDocumentContentService $contentService,
    ): KnowledgeDocumentResource {
        Gate::authorize('view', $knowledgeDocument);

        return KnowledgeDocumentResource::make(
            $knowledgeDocument->load('supersedes:id,title,revision_number'),
        )->withVersionHistory($contentService->versionHistory($knowledgeDocument));
    }

    public function content(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocument $knowledgeDocument,
        KnowledgeDocumentContentService $contentService,
    ): StreamedResponse|JsonResponse {
        Gate::authorize('view', $knowledgeDocument);

        return $contentService->buildContentResponse($knowledgeDocument);
    }

    public function activate(
        ActivateKnowledgeDocumentRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocument $knowledgeDocument,
        KnowledgeDocumentLifecycleService $lifecycleService,
    ): KnowledgeDocumentResource|JsonResponse {
        try {
            $document = $lifecycleService->activate($knowledgeDocument);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return KnowledgeDocumentResource::make(
            $document->load('supersedes:id,title,revision_number'),
        );
    }

    public function archive(
        ArchiveKnowledgeDocumentRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocument $knowledgeDocument,
        KnowledgeDocumentLifecycleService $lifecycleService,
    ): KnowledgeDocumentResource {
        $document = $lifecycleService->archive($knowledgeDocument);

        return KnowledgeDocumentResource::make(
            $document->load('supersedes:id,title,revision_number'),
        );
    }

    public function destroy(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocument $knowledgeDocument,
        AiServiceClient $aiService,
    ): Response {
        Gate::authorize('delete', $knowledgeDocument);

        $aiService->deleteDocumentVectors($knowledgeDocument);

        Storage::disk('local')->delete($knowledgeDocument->disk_path);
        $knowledgeDocument->delete();

        return response()->noContent();
    }

    private function storeUploadedFile(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        UploadedFile $file,
    ): string {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $filename = Str::uuid()->toString().'.'.$extension;

        return $file->storeAs(
            sprintf(
                'knowledge/%d/%d/%d',
                $workspace->id,
                $customer->id,
                $deployment->id,
            ),
            $filename,
            'local',
        );
    }
}
