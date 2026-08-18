<?php

namespace App\Http\Controllers;

use App\Enums\ProjectFactStatus;
use App\Exceptions\CopilotException;
use App\Http\Requests\BulkRejectProjectFactsRequest;
use App\Http\Requests\BulkVerifyProjectFactsRequest;
use App\Http\Requests\ExtractProjectFactsRequest;
use App\Http\Requests\IndexProjectFactsRequest;
use App\Http\Requests\StoreProjectFactRequest;
use App\Http\Requests\UpdateProjectFactRequest;
use App\Http\Resources\ProjectFactExtractionResource;
use App\Http\Resources\ProjectFactResource;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\ProjectFact;
use App\Models\ProjectFactExtraction;
use App\Models\Workspace;
use App\Services\ProjectFactExtractionService;
use App\Services\ProjectFactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectFactController extends Controller
{
    /**
     * @return list<string>
     */
    private function factRelations(): array
    {
        return [
            'sourceDocument:id,title,revision_number,original_filename',
            'creator:id,name,email',
            'verifier:id,name,email',
        ];
    }

    public function index(
        IndexProjectFactsRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        ProjectFactService $projectFactService,
    ): AnonymousResourceCollection {
        $validated = $request->validated();
        $status = isset($validated['status'])
            ? ProjectFactStatus::from($validated['status'])
            : null;
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $category = isset($validated['category']) ? trim((string) $validated['category']) : '';
        $sourceDocumentId = isset($validated['source_document_id'])
            ? (int) $validated['source_document_id']
            : null;

        $query = ProjectFact::query()
            ->forDeployment($deployment)
            ->withStatus($status)
            ->with($this->factRelations())
            ->orderByDesc('updated_at');

        if ($category !== '') {
            $query->where('category', $category);
        }

        if ($sourceDocumentId !== null) {
            $query->where('source_document_id', $sourceDocumentId);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('category', 'like', '%'.$search.'%')
                    ->orWhere('key', 'like', '%'.$search.'%')
                    ->orWhere('value', 'like', '%'.$search.'%')
                    ->orWhere('source_reference', 'like', '%'.$search.'%')
                    ->orWhereHas('sourceDocument', function ($documentQuery) use ($search): void {
                        $documentQuery->where('title', 'like', '%'.$search.'%');
                    });
            });
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 25));

        return ProjectFactResource::collection($paginator)->additional([
            'stats' => $projectFactService->statsForDeployment($deployment),
            'filter_options' => [
                'categories' => ProjectFact::query()
                    ->forDeployment($deployment)
                    ->distinct()
                    ->orderBy('category')
                    ->pluck('category')
                    ->values()
                    ->all(),
                'source_documents' => ProjectFact::query()
                    ->forDeployment($deployment)
                    ->whereNotNull('source_document_id')
                    ->with('sourceDocument:id,title,revision_number')
                    ->get()
                    ->pluck('sourceDocument')
                    ->filter()
                    ->unique('id')
                    ->sortBy('title')
                    ->values()
                    ->map(fn (KnowledgeDocument $document): array => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'revision_number' => $document->revision_number,
                    ])
                    ->all(),
            ],
        ]);
    }

    public function store(
        StoreProjectFactRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        ProjectFactService $projectFactService,
    ): JsonResponse {
        $validated = $request->validated();
        $sourceDocumentId = isset($validated['source_document_id'])
            ? (int) $validated['source_document_id']
            : null;

        $sourceRevision = null;

        if ($sourceDocumentId !== null) {
            $document = $projectFactService->assertAuthoritativeSourceDocument($deployment, $sourceDocumentId);
            $sourceRevision = $document->revision_number;
        }

        $fact = $projectFactService->propose($request->user(), $deployment, [
            'category' => $validated['category'],
            'key' => $validated['key'],
            'value' => $validated['value'],
            'source_document_id' => $sourceDocumentId,
            'source_revision' => $sourceRevision,
            'source_reference' => $validated['source_reference'] ?? null,
            'confidence' => $validated['confidence'] ?? null,
        ]);

        return ProjectFactResource::make($fact->load($this->factRelations()))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        ProjectFact $projectFact,
    ): ProjectFactResource {
        Gate::authorize('view', $projectFact);

        return ProjectFactResource::make($projectFact->load($this->factRelations()));
    }

    public function update(
        UpdateProjectFactRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        ProjectFact $projectFact,
        ProjectFactService $projectFactService,
    ): ProjectFactResource {
        $fact = $projectFactService->updateProposed(
            $projectFact,
            $request->user(),
            $request->validated(),
        );

        return ProjectFactResource::make($fact);
    }

    public function verify(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        ProjectFact $projectFact,
        ProjectFactService $projectFactService,
    ): ProjectFactResource {
        $fact = $projectFactService->verify($projectFact, request()->user());

        return ProjectFactResource::make($fact);
    }

    public function reject(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        ProjectFact $projectFact,
        ProjectFactService $projectFactService,
    ): ProjectFactResource {
        $fact = $projectFactService->reject($projectFact, request()->user());

        return ProjectFactResource::make($fact);
    }

    public function bulkVerify(
        BulkVerifyProjectFactsRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        ProjectFactService $projectFactService,
    ): AnonymousResourceCollection {
        $ids = array_map(intval(...), $request->validated('ids'));
        $facts = $projectFactService->verifyMany($request->user(), $deployment, $ids);

        return ProjectFactResource::collection($facts)->additional([
            'stats' => $projectFactService->statsForDeployment($deployment),
            'processed_count' => $facts->count(),
        ]);
    }

    public function bulkReject(
        BulkRejectProjectFactsRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        ProjectFactService $projectFactService,
    ): AnonymousResourceCollection {
        $validated = $request->validated();

        $facts = isset($validated['ids'])
            ? $projectFactService->rejectMany(
                $request->user(),
                $deployment,
                array_map(intval(...), $validated['ids']),
            )
            : $projectFactService->rejectProposedFromSource(
                $request->user(),
                $deployment,
                (int) $validated['source_document_id'],
                (int) $validated['source_revision'],
            );

        return ProjectFactResource::collection($facts)->additional([
            'stats' => $projectFactService->statsForDeployment($deployment),
            'processed_count' => $facts->count(),
        ]);
    }

    public function extract(
        ExtractProjectFactsRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocument $knowledgeDocument,
        ProjectFactExtractionService $extractionService,
    ): JsonResponse {
        if ($knowledgeDocument->deployment_id !== $deployment->id) {
            abort(404);
        }

        try {
            $extraction = $extractionService->queue($request->user(), $knowledgeDocument);
        } catch (CopilotException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->statusCode >= 400 ? $exception->statusCode : 422);
        }

        return ProjectFactExtractionResource::make($extraction)
            ->response()
            ->setStatusCode(202);
    }

    public function showExtraction(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        ProjectFactExtraction $projectFactExtraction,
    ): ProjectFactExtractionResource {
        Gate::authorize('view', $projectFactExtraction);

        return ProjectFactExtractionResource::make($projectFactExtraction);
    }
}
