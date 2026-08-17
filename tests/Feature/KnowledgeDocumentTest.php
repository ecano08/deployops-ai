<?php

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

it('allows workspace members to list knowledge documents', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->create([
        'original_filename' => 'runbook.txt',
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonPath('data.0.original_filename', 'runbook.txt')
        ->assertJsonPath('data.0.status', KnowledgeDocumentStatus::Ready->value);
});

it('allows engineers to upload knowledge documents', function () {
    Queue::fake();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->postJson(
        knowledgeDocumentsPath($fixture['workspace'], $customer, $deployment),
        [
            'file' => UploadedFile::fake()->create('runbook.txt', 20, 'text/plain'),
        ],
        ['Accept' => 'application/json'],
    );

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', KnowledgeDocumentStatus::Pending->value)
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

it('executes search_knowledge with deployment scope from server context', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

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

    Http::assertSent(function ($request) use ($fixture, $customer, $deployment) {
        $body = $request->data();

        return $request->url() === 'http://ai-service.test/search'
            && $request->hasHeader('X-AI-Service-Token', 'test-ai-service-token')
            && $body['workspace_id'] === $fixture['workspace']->id
            && $body['customer_id'] === $customer->id
            && $body['deployment_id'] === $deployment->id
            && $body['query'] === 'rollback steps'
            && $body['top_k'] === 3
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
