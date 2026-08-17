<?php

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\Workspace;
use App\Services\AiServiceClient;
use App\Services\CopilotContext;
use App\Services\CopilotToolExecutor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

function knowledgeDocumentsPath(Workspace $workspace, Customer $customer, Deployment $deployment, ?KnowledgeDocument $document = null): string
{
    $path = '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/knowledge-documents';

    if ($document !== null) {
        $path .= '/'.$document->id;
    }

    return $path;
}

function knowledgeDocumentActionPath(
    Workspace $workspace,
    Customer $customer,
    Deployment $deployment,
    KnowledgeDocument $document,
    string $action,
): string {
    return knowledgeDocumentsPath($workspace, $customer, $deployment, $document).'/'.$action;
}

function knowledgeDocumentContentPath(
    Workspace $workspace,
    Customer $customer,
    Deployment $deployment,
    KnowledgeDocument $document,
): string {
    return knowledgeDocumentsPath($workspace, $customer, $deployment, $document).'/content';
}

beforeEach(function () {
    Storage::fake('local');
    config([
        'services.ai_service.url' => 'http://ai-service.test',
        'services.ai_service.token' => 'test-ai-service-token',
        'services.openai.api_key' => 'test-openai-key',
        'services.openai.model' => 'gpt-4.1-mini',
    ]);
});

it('requires authentication to list knowledge documents', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $this->getJson(knowledgeDocumentsPath($workspace, $customer, $deployment))
        ->assertUnauthorized();
});

it('allows workspace members to list knowledge document library entries', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'original_filename' => 'runbook.txt',
        'title' => 'Runbook',
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Runbook')
        ->assertJsonPath('data.0.active_revision.original_filename', 'runbook.txt')
        ->assertJsonPath('data.0.active_revision.status', KnowledgeDocumentStatus::Ready->value)
        ->assertJsonPath('data.0.active_revision.lifecycle_status', KnowledgeDocumentLifecycleStatus::Active->value)
        ->assertJsonPath('stats.revision_total', 1)
        ->assertJsonPath('stats.ready_count', 1)
        ->assertJsonPath('stats.active_count', 1);
});

it('paginates knowledge document library entries by version chain', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Alpha runbook',
    ]);
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Beta architecture',
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment).'?per_page=1&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2);
});

it('filters knowledge document library entries that need attention', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $active = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Auth model',
        'revision_number' => 1,
    ]);
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->draft()->create([
        'title' => 'Auth model',
        'revision_number' => 2,
        'supersedes_document_id' => $active->id,
        'chain_root_id' => $active->chain_root_id,
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment).'?view=needs_attention')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.needs_attention', true)
        ->assertJsonPath('data.0.attention_draft.revision_number', 2);
});

it('searches knowledge document library entries by title and filename', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Reservation policy',
        'original_filename' => 'reservation-policy.pdf',
    ]);
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Deployment runbook',
        'original_filename' => 'runbook.txt',
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment).'?search=reservation')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Reservation policy');
});

it('allows engineers to upload knowledge documents', function () {
    Queue::fake();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->post(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment),
        [
            'file' => UploadedFile::fake()->create('runbook.txt', 20, 'text/plain'),
            'title' => 'Operations runbook',
            'document_type' => 'operations',
        ],
        ['Accept' => 'application/json'],
    );

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', KnowledgeDocumentStatus::Pending->value)
        ->assertJsonPath('data.lifecycle_status', KnowledgeDocumentLifecycleStatus::Draft->value)
        ->assertJsonPath('data.revision_number', 1)
        ->assertJsonPath('data.title', 'Operations runbook')
        ->assertJsonPath('data.document_type', 'operations')
        ->assertJsonPath('data.original_filename', 'runbook.txt');

    Queue::assertPushed(ProcessKnowledgeDocumentJob::class);

    $document = KnowledgeDocument::query()->first();

    expect($document)->not->toBeNull()
        ->and(Storage::disk('local')->exists($document->disk_path))->toBeTrue();
});

it('forbids viewers from uploading knowledge documents', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->post(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment),
        [
            'file' => UploadedFile::fake()->create('runbook.txt', 20, 'text/plain'),
            'title' => 'Runbook',
            'document_type' => 'other',
        ],
        ['Accept' => 'application/json'],
    )->assertForbidden();
});

it('rejects unsupported knowledge document types', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->post(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment),
        [
            'file' => UploadedFile::fake()->create('notes.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'title' => 'Notes',
            'document_type' => 'other',
        ],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

it('processes uploaded documents through the ai service', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'original_filename' => 'runbook.txt',
        'mime_type' => 'text/plain',
        'disk_path' => 'knowledge/test/runbook.txt',
        'status' => KnowledgeDocumentStatus::Pending,
    ]);

    Storage::disk('local')->put($document->disk_path, 'Rollback steps: stop traffic.');

    Http::fake([
        'http://ai-service.test/documents/process' => Http::response([
            'chunk_count' => 2,
        ]),
    ]);

    (new ProcessKnowledgeDocumentJob($document))->handle(app(AiServiceClient::class));

    $document->refresh();

    expect($document->status)->toBe(KnowledgeDocumentStatus::Ready)
        ->and($document->chunk_count)->toBe(2);

    Http::assertSent(function ($request) use ($document) {
        $body = $request->data();

        return $request->url() === 'http://ai-service.test/documents/process'
            && $request->hasHeader('X-AI-Service-Token', 'test-ai-service-token')
            && $body['workspace_id'] === $document->workspace_id
            && $body['customer_id'] === $document->customer_id
            && $body['deployment_id'] === $document->deployment_id
            && $body['document_id'] === $document->id
            && ! array_key_exists('openai_api_key', $body)
            && ! array_key_exists('api_key', $body);
    });
});

it('marks documents failed when processing fails', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'disk_path' => 'knowledge/test/missing.txt',
        'status' => KnowledgeDocumentStatus::Pending,
    ]);

    (new ProcessKnowledgeDocumentJob($document))->handle(app(AiServiceClient::class));

    $document->refresh();

    expect($document->status)->toBe(KnowledgeDocumentStatus::Failed)
        ->and($document->error_message)->toBe('Document processing failed.');
});

it('deletes knowledge documents and requests vector cleanup', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->create([
        'disk_path' => 'knowledge/test/runbook.txt',
    ]);

    Storage::disk('local')->put($document->disk_path, 'content');

    Http::fake([
        'http://ai-service.test/documents/delete' => Http::response(['status' => 'deleted']),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->deleteJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertNoContent();

    expect(KnowledgeDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($document->disk_path))->toBeFalse();

    Http::assertSent(function ($request) use ($document) {
        $body = $request->data();

        return $request->url() === 'http://ai-service.test/documents/delete'
            && $request->hasHeader('X-AI-Service-Token', 'test-ai-service-token')
            && $body['document_id'] === $document->id;
    });
});

it('retrieves reservation policy chunks for unpaid reservation timeout queries', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'original_filename' => 'reservation-policy.pdf',
    ]);

    Http::fake([
        'http://ai-service.test/search' => Http::response([
            'results' => [
                [
                    'document_id' => $document->id,
                    'source_filename' => 'reservation-policy.pdf',
                    'chunk_index' => 0,
                    'content' => 'Cart reservation lasts 15 minutes. If the user does not pay, the reservation is automatically released.',
                    'score' => 0.94,
                ],
            ],
        ]),
    ]);

    $context = new CopilotContext(
        user: $fixture['viewer'],
        workspace: $fixture['workspace'],
        customer: $customer,
        deployment: $deployment,
        currentQuestion: 'What happens if the user does not pay within the 15-minute reservation?',
    );

    $result = app(CopilotToolExecutor::class)->validateAndExecute($context, 'search_knowledge', [
        'query' => 'What happens if the user does not pay within the 15-minute reservation?',
        'top_k' => 5,
    ]);

    expect($result['results'][0]['source_filename'])->toBe('reservation-policy.pdf')
        ->and($result['results'][0]['content'])->toContain('15 minutes')
        ->and($result['results'][0]['content'])->toContain('automatically released');

    Http::assertSent(function ($request) use ($fixture, $customer, $deployment, $document) {
        $body = $request->data();

        return $request->url() === 'http://ai-service.test/search'
            && $body['workspace_id'] === $fixture['workspace']->id
            && $body['customer_id'] === $customer->id
            && $body['deployment_id'] === $deployment->id
            && $body['document_ids'] === [$document->id]
            && str_contains($body['query'], '15')
            && str_contains($body['query'], 'reservation')
            && in_array('15', $body['lexical_terms'], true)
            && in_array('reservation', $body['lexical_terms'], true);
    });
});

it('executes search_knowledge with deployment scope from server context', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();

    Http::fake([
        'http://ai-service.test/search' => Http::response([
            'results' => [
                [
                    'document_id' => 9,
                    'source_filename' => 'runbook.txt',
                    'chunk_index' => 0,
                    'content' => 'Rollback steps: stop traffic.',
                    'score' => 0.92,
                ],
            ],
        ]),
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $context = new CopilotContext(
        user: $fixture['viewer'],
        workspace: $fixture['workspace'],
        customer: $customer,
        deployment: $deployment,
    );

    $result = app(CopilotToolExecutor::class)->validateAndExecute($context, 'search_knowledge', [
        'query' => 'rollback steps',
        'top_k' => 3,
    ]);

    expect($result['results'][0]['source_filename'])->toBe('runbook.txt')
        ->and($result['results'][0]['content'])->toContain('Rollback steps');

    Http::assertSent(function ($request) use ($fixture, $customer, $deployment, $document) {
        $body = $request->data();

        return $request->url() === 'http://ai-service.test/search'
            && $request->hasHeader('X-AI-Service-Token', 'test-ai-service-token')
            && $body['workspace_id'] === $fixture['workspace']->id
            && $body['customer_id'] === $customer->id
            && $body['deployment_id'] === $deployment->id
            && $body['query'] === 'rollback steps'
            && $body['top_k'] === 3
            && $body['document_ids'] === [$document->id]
            && ! array_key_exists('openai_api_key', $body);
    });
});

it('never trusts model-provided tenant ids for knowledge search', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'http://ai-service.test/search' => Http::response(['results' => []]),
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $context = new CopilotContext(
        user: $fixture['viewer'],
        workspace: $fixture['workspace'],
        customer: $customer,
        deployment: $deployment,
    );

    $validation = app(CopilotToolExecutor::class)->validateArguments('search_knowledge', [
        'query' => 'rollback',
        'workspace_id' => 999,
    ]);

    expect($validation)->toBe(['error' => 'Unexpected tool arguments.']);
});

it('uses search_knowledge in copilot responses with source attribution', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('search_knowledge', json_encode([
                'query' => 'rollback steps',
                'top_k' => 5,
            ])))
            ->push(openAiMessageResponse('According to runbook.txt, rollback steps are to stop traffic.')),
        'http://ai-service.test/search' => Http::response([
            'results' => [
                [
                    'document_id' => 1,
                    'source_filename' => 'runbook.txt',
                    'chunk_index' => 0,
                    'content' => 'Rollback steps: stop traffic.',
                    'score' => 0.95,
                ],
            ],
        ]),
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'What are the rollback steps?',
    ])
        ->assertOk()
        ->assertJsonPath('data.tools_used', ['search_knowledge'])
        ->assertJsonPath('data.answer', 'According to runbook.txt, rollback steps are to stop traffic.');

    Http::assertSent(function ($request) {
        if ($request->url() !== 'http://ai-service.test/search') {
            return false;
        }

        return $request->hasHeader('X-AI-Service-Token', 'test-ai-service-token')
            && ! str_contains($request->body(), 'test-openai-key');
    });
});

it('omits the internal ai service token when it is not configured', function () {
    config(['services.ai_service.token' => null]);

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();

    Http::fake([
        'http://ai-service.test/search' => Http::response(['results' => []], 401),
    ]);

    $context = new CopilotContext(
        user: $fixture['viewer'],
        workspace: $fixture['workspace'],
        customer: $customer,
        deployment: $deployment,
    );

    $result = app(CopilotToolExecutor::class)->validateAndExecute($context, 'search_knowledge', [
        'query' => 'rollback steps',
        'top_k' => 5,
    ]);

    expect($result)->toHaveKey('error');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/search'
            && ! $request->hasHeader('X-AI-Service-Token');
    });
});

it('validates required project documentation metadata on upload', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->post(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment),
        [
            'file' => UploadedFile::fake()->create('runbook.txt', 20, 'text/plain'),
        ],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['document_type']);
});

it('defaults title from filename when title is omitted', function () {
    Queue::fake();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->post(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment),
        [
            'file' => UploadedFile::fake()->create('operations-runbook.txt', 20, 'text/plain'),
            'document_type' => 'operations',
        ],
        ['Accept' => 'application/json'],
    )->assertCreated()
        ->assertJsonPath('data.title', 'operations runbook')
        ->assertJsonPath('data.revision_number', 1);
});

it('assigns incremental revision numbers within a version chain', function () {
    Queue::fake();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $original = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Auth model',
        'revision_number' => 1,
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->post(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment),
        [
            'file' => UploadedFile::fake()->create('auth-v2.txt', 30, 'text/plain'),
            'title' => 'Auth model',
            'document_type' => 'authorization',
            'supersedes_document_id' => $original->id,
        ],
        ['Accept' => 'application/json'],
    )->assertCreated()
        ->assertJsonPath('data.revision_number', 2)
        ->assertJsonPath('data.supersedes_document_id', $original->id);
});

it('rejects uploading an identical file within the same deployment', function () {
    Queue::fake();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['engineer']);

    $file = UploadedFile::fake()->create('runbook.txt', 20, 'text/plain');
    $contentHash = hash('sha256', $file->get());

    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'title' => 'Runbook',
        'revision_number' => 1,
        'content_hash' => $contentHash,
    ]);

    $this->post(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment),
        [
            'file' => $file,
            'title' => 'Runbook copy',
            'document_type' => 'operations',
        ],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

it('returns likely document matches for normalized filenames', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Auth Model',
        'original_filename' => 'auth-model.txt',
        'revision_number' => 2,
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment).'/match-candidates',
        [
            'filename' => 'Auth-Model.pdf',
        ],
    )->assertOk()
        ->assertJsonPath('data.0.id', $document->id)
        ->assertJsonPath('data.0.chain_head_id', $document->id)
        ->assertJsonPath('data.0.chain_head_revision_number', 2);
});

it('creates a new document version without overwriting the previous record', function () {
    Queue::fake();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $original = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Auth model',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->post(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment),
        [
            'file' => UploadedFile::fake()->create('auth-v2.txt', 20, 'text/plain'),
            'title' => 'Auth model',
            'document_type' => 'authorization',
            'version_label' => 'v2',
            'supersedes_document_id' => $original->id,
        ],
        ['Accept' => 'application/json'],
    )->assertCreated()
        ->assertJsonPath('data.lifecycle_status', KnowledgeDocumentLifecycleStatus::Draft->value)
        ->assertJsonPath('data.revision_number', 2)
        ->assertJsonPath('data.supersedes_document_id', $original->id);

    expect(KnowledgeDocument::query()->count())->toBe(2)
        ->and($original->fresh()->lifecycle_status)->toBe(KnowledgeDocumentLifecycleStatus::Active);
});

it('activates a ready draft and supersedes the previous active version transactionally', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $original = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Auth model',
    ]);
    $successor = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->draft()->create([
        'title' => 'Auth model',
        'supersedes_document_id' => $original->id,
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(knowledgeDocumentActionPath($fixture['workspace'], $customer, $deployment, $successor, 'activate'))
        ->assertOk()
        ->assertJsonPath('data.lifecycle_status', KnowledgeDocumentLifecycleStatus::Active->value);

    $original->refresh();
    $successor->refresh();

    expect($original->lifecycle_status)->toBe(KnowledgeDocumentLifecycleStatus::Superseded)
        ->and($successor->lifecycle_status)->toBe(KnowledgeDocumentLifecycleStatus::Active)
        ->and(KnowledgeDocument::query()->count())->toBe(2);
});

it('rejects activation for failed documents', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $active = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();
    $failed = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->failed()->draft()->create([
        'supersedes_document_id' => $active->id,
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(knowledgeDocumentActionPath($fixture['workspace'], $customer, $deployment, $failed, 'activate'))
        ->assertUnprocessable();

    expect($active->fresh()->lifecycle_status)->toBe(KnowledgeDocumentLifecycleStatus::Active)
        ->and($failed->fresh()->lifecycle_status)->toBe(KnowledgeDocumentLifecycleStatus::Draft);
});

it('excludes non-authoritative documents from rag search', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $activeReady = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();

    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->draft()->create();
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->create([
        'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Superseded,
    ]);
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->create([
        'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Archived,
    ]);
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->active()->create([
        'status' => KnowledgeDocumentStatus::Failed,
    ]);

    Http::fake([
        'http://ai-service.test/search' => Http::response(['results' => []]),
    ]);

    $context = new CopilotContext(
        user: $fixture['viewer'],
        workspace: $fixture['workspace'],
        customer: $customer,
        deployment: $deployment,
    );

    app(CopilotToolExecutor::class)->validateAndExecute($context, 'search_knowledge', [
        'query' => 'rollback',
        'top_k' => 5,
    ]);

    Http::assertSent(function ($request) use ($activeReady) {
        $body = $request->data();

        return $request->url() === 'http://ai-service.test/search'
            && $body['document_ids'] === [$activeReady->id];
    });
});

it('returns empty rag results when no authoritative documents exist', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->draft()->create();

    Http::fake();

    $context = new CopilotContext(
        user: $fixture['viewer'],
        workspace: $fixture['workspace'],
        customer: $customer,
        deployment: $deployment,
    );

    $result = app(CopilotToolExecutor::class)->validateAndExecute($context, 'search_knowledge', [
        'query' => 'rollback',
        'top_k' => 5,
    ]);

    expect($result['results'])->toBe([]);
    Http::assertNothingSent();
});

it('isolates knowledge documents to their deployment', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $otherDeployment, $document))
        ->assertNotFound();
});

it('forbids viewers from activating or archiving documents', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->draft()->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(knowledgeDocumentActionPath($fixture['workspace'], $customer, $deployment, $document, 'activate'))
        ->assertForbidden();

    $this->postJson(knowledgeDocumentActionPath($fixture['workspace'], $customer, $deployment, $document, 'archive'))
        ->assertForbidden();
});

it('archives a document without deleting historical versions', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(knowledgeDocumentActionPath($fixture['workspace'], $customer, $deployment, $document, 'archive'))
        ->assertOk()
        ->assertJsonPath('data.lifecycle_status', KnowledgeDocumentLifecycleStatus::Archived->value);

    expect(KnowledgeDocument::query()->count())->toBe(1);
});

it('requires authentication to view knowledge document content', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'disk_path' => 'knowledge/test/runbook.txt',
    ]);

    Storage::disk('local')->put($document->disk_path, 'Rollback steps: stop traffic.');

    $this->getJson(knowledgeDocumentContentPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertUnauthorized();
});

it('allows workspace viewers to inspect knowledge document details and content', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $original = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Auth model',
        'revision_number' => 1,
        'disk_path' => 'knowledge/test/auth-v1.txt',
        'original_filename' => 'auth-v1.txt',
        'mime_type' => 'text/plain',
    ]);
    $successor = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->draft()->create([
        'title' => 'Auth model',
        'revision_number' => 2,
        'supersedes_document_id' => $original->id,
        'disk_path' => 'knowledge/test/auth-v2.txt',
        'original_filename' => 'auth-v2.txt',
        'mime_type' => 'text/plain',
    ]);

    Storage::disk('local')->put($original->disk_path, 'Version one content.');
    Storage::disk('local')->put($successor->disk_path, 'Version two content.');

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment, $successor))
        ->assertOk()
        ->assertJsonPath('data.id', $successor->id)
        ->assertJsonPath('data.preview_format', 'text')
        ->assertJsonCount(2, 'data.version_history')
        ->assertJsonPath('data.version_history.0.id', $successor->id)
        ->assertJsonPath('data.version_history.1.id', $original->id);

    $response = $this->get(knowledgeDocumentContentPath($fixture['workspace'], $customer, $deployment, $successor));

    $response
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="auth-v2.txt"');

    expect($response->streamedContent())->toContain('Version two content.');
});

it('streams pdf knowledge documents inline for preview', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'disk_path' => 'knowledge/test/policy.pdf',
        'original_filename' => 'policy.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($document->disk_path, '%PDF-1.4 preview');

    Sanctum::actingAs($fixture['viewer']);

    $response = $this->get(knowledgeDocumentContentPath($fixture['workspace'], $customer, $deployment, $document));

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="policy.pdf"');

    expect($response->streamedContent())->toContain('%PDF-1.4 preview');
});

it('returns a clear preview state when document content is not ready', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'status' => KnowledgeDocumentStatus::Processing,
        'disk_path' => 'knowledge/test/runbook.txt',
    ]);

    Storage::disk('local')->put($document->disk_path, 'Still processing.');

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(knowledgeDocumentContentPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertUnprocessable()
        ->assertJsonPath('preview_state', KnowledgeDocumentStatus::Processing->value)
        ->assertJsonPath('message', 'Document is still processing and cannot be previewed yet.');
});

it('denies knowledge document viewing across deployments', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'disk_path' => 'knowledge/test/runbook.txt',
    ]);

    Storage::disk('local')->put($document->disk_path, 'Deployment scoped content.');

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $otherDeployment, $document))
        ->assertNotFound();

    $this->get(knowledgeDocumentContentPath($fixture['workspace'], $customer, $otherDeployment, $document))
        ->assertNotFound();
});

it('denies knowledge document viewing to users outside the workspace', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'disk_path' => 'knowledge/test/runbook.txt',
    ]);

    Storage::disk('local')->put($document->disk_path, 'Private content.');

    Sanctum::actingAs($fixture['stranger']);

    $this->getJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertForbidden();

    $this->get(knowledgeDocumentContentPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertForbidden();
});
