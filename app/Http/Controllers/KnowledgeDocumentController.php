<?php

namespace App\Http\Controllers;

use App\Enums\KnowledgeDocumentStatus;
use App\Http\Requests\StoreKnowledgeDocumentRequest;
use App\Http\Resources\KnowledgeDocumentResource;
use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\Workspace;
use App\Services\AiServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KnowledgeDocumentController extends Controller
{
    public function index(Workspace $workspace, Customer $customer, Deployment $deployment): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [KnowledgeDocument::class, $workspace]);

        $documents = $deployment->knowledgeDocuments()
            ->orderByDesc('created_at')
            ->get();

        return KnowledgeDocumentResource::collection($documents);
    }

    public function store(
        StoreKnowledgeDocumentRequest $request,
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
    ): JsonResponse {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $diskPath = $this->storeUploadedFile($workspace, $customer, $deployment, $file);

        $document = KnowledgeDocument::query()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'deployment_id' => $deployment->id,
            'uploaded_by' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => (string) $file->getMimeType(),
            'disk_path' => $diskPath,
            'size_bytes' => $file->getSize(),
            'status' => KnowledgeDocumentStatus::Pending,
        ]);

        ProcessKnowledgeDocumentJob::dispatch($document);

        return KnowledgeDocumentResource::make($document)
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Workspace $workspace,
        Customer $customer,
        Deployment $deployment,
        KnowledgeDocument $knowledgeDocument,
    ): KnowledgeDocumentResource {
        Gate::authorize('view', $knowledgeDocument);

        return KnowledgeDocumentResource::make($knowledgeDocument);
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
