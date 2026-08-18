<?php

use App\Enums\ProjectFactExtractionStatus;
use App\Enums\ProjectFactStatus;
use App\Jobs\ExtractProjectFactsJob;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\ProjectFact;
use App\Models\ProjectFactExtraction;
use App\Models\Workspace;
use App\Services\ProjectFactExtractionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

function projectFactsPath(Workspace $workspace, Customer $customer, Deployment $deployment, ?ProjectFact $fact = null): string
{
    $path = '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/project-facts';

    if ($fact !== null) {
        $path .= '/'.$fact->id;
    }

    return $path;
}

function projectFactActionPath(
    Workspace $workspace,
    Customer $customer,
    Deployment $deployment,
    ProjectFact $fact,
    string $action,
): string {
    return projectFactsPath($workspace, $customer, $deployment, $fact).'/'.$action;
}

function projectFactsBulkPath(
    Workspace $workspace,
    Customer $customer,
    Deployment $deployment,
    string $action,
): string {
    return projectFactsPath($workspace, $customer, $deployment).'/'.$action;
}

function extractFactsPath(
    Workspace $workspace,
    Customer $customer,
    Deployment $deployment,
    KnowledgeDocument $document,
): string {
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/knowledge-documents/'.$document->id.'/extract-facts';
}

function projectFactExtractionPath(
    Workspace $workspace,
    Customer $customer,
    Deployment $deployment,
    ProjectFactExtraction $extraction,
): string {
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/project-fact-extractions/'.$extraction->id;
}

function assertFinishedProjectFactExtraction(
    mixed $response,
    ProjectFactExtractionStatus $status,
    int $proposedCount = 0,
): ProjectFactExtraction {
    $response
        ->assertAccepted()
        ->assertJsonPath('data.status', ProjectFactExtractionStatus::Pending->value);

    $extraction = ProjectFactExtraction::query()->findOrFail($response->json('data.id'));

    expect($extraction->status)->toBe($status)
        ->and($extraction->proposed_count)->toBe($proposedCount);

    return $extraction;
}

function runExtractProjectFactsJob(ProjectFactExtraction $extraction): void
{
    (new ExtractProjectFactsJob($extraction))->handle(app(ProjectFactExtractionService::class));
}

function openAiFactExtractionResponse(array $facts): array
{
    return openAiToolCallResponse(
        'propose_project_facts',
        json_encode(['facts' => $facts], JSON_THROW_ON_ERROR),
    );
}

function openaiExtractionInputText(Request $request): string
{
    $body = $request->data();
    $input = is_array($body) ? ($body['input'] ?? null) : null;

    if (! is_array($input)) {
        return '';
    }

    return (string) data_get($input, '0.content.0.text', '');
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

it('requires authentication to list project facts', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $this->getJson(projectFactsPath($workspace, $customer, $deployment))
        ->assertUnauthorized();
});

it('allows workspace members to list project facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(projectFactsPath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonPath('data.0.category', 'framework')
        ->assertJsonPath('data.0.key', 'backend')
        ->assertJsonPath('data.0.value', 'Laravel 13')
        ->assertJsonPath('stats.proposed_count', 1);
});

it('filters project facts by status', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create();
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'key' => 'primary',
        'category' => 'database',
        'value' => 'MySQL',
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(projectFactsPath($fixture['workspace'], $customer, $deployment).'?status=verified')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', ProjectFactStatus::Verified->value);
});

it('filters project facts by category', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
    ]);
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'database',
        'key' => 'primary',
        'value' => 'PostgreSQL',
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(projectFactsPath($fixture['workspace'], $customer, $deployment).'?category=framework')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category', 'framework')
        ->assertJsonPath('filter_options.categories', ['database', 'framework']);
});

it('filters project facts by source document', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $architecture = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Architecture',
        'revision_number' => 2,
    ]);
    $operations = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Operations',
        'revision_number' => 1,
    ]);
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
        'source_document_id' => $architecture->id,
        'source_revision' => $architecture->revision_number,
    ]);
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'operations',
        'key' => 'deploy',
        'value' => 'Herd',
        'source_document_id' => $operations->id,
        'source_revision' => $operations->revision_number,
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(projectFactsPath($fixture['workspace'], $customer, $deployment).'?source_document_id='.$architecture->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.source_document_id', $architecture->id)
        ->assertJsonPath('filter_options.source_documents.0.title', 'Architecture');
});

it('searches project facts by source document title', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Security Runbook',
        'revision_number' => 1,
    ]);
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'security',
        'key' => 'auth',
        'value' => 'required',
        'source_document_id' => $document->id,
        'source_revision' => $document->revision_number,
        'source_reference' => 'Authentication is required.',
    ]);
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(projectFactsPath($fixture['workspace'], $customer, $deployment).'?search=runbook')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category', 'security');
});

it('preserves provenance when creating manual proposed facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Architecture',
        'revision_number' => 3,
        'disk_path' => 'knowledge/test/architecture.txt',
    ]);
    Storage::disk('local')->put($document->disk_path, 'Backend uses Laravel 13.');

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsPath($fixture['workspace'], $customer, $deployment), [
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
        'source_document_id' => $document->id,
        'source_reference' => 'Backend uses Laravel 13.',
        'confidence' => 0.95,
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', ProjectFactStatus::Proposed->value)
        ->assertJsonPath('data.source_document_id', $document->id)
        ->assertJsonPath('data.source_revision', 3)
        ->assertJsonPath('data.source_reference', 'Backend uses Laravel 13.')
        ->assertJsonPath('data.source_document.title', 'Architecture');
});

it('rejects facts sourced from non-authoritative documentation', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->draft()->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsPath($fixture['workspace'], $customer, $deployment), [
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
        'source_document_id' => $document->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source_document_id']);
});

it('allows engineers to verify proposed facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactActionPath($fixture['workspace'], $customer, $deployment, $fact, 'verify'))
        ->assertOk()
        ->assertJsonPath('data.status', ProjectFactStatus::Verified->value)
        ->assertJsonPath('data.verified_by.id', $fixture['engineer']->id);

    expect($fact->fresh()->status)->toBe(ProjectFactStatus::Verified)
        ->and($fact->fresh()->verified_at)->not->toBeNull();
});

it('rejects proposed facts and keeps them historical', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create();

    Sanctum::actingAs($fixture['admin']);

    $this->postJson(projectFactActionPath($fixture['workspace'], $customer, $deployment, $fact, 'reject'))
        ->assertOk()
        ->assertJsonPath('data.status', ProjectFactStatus::Rejected->value);

    expect(ProjectFact::query()->count())->toBe(1)
        ->and($fact->fresh()->status)->toBe(ProjectFactStatus::Rejected);
});

it('supersedes older verified facts when a new fact is verified', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $existing = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 12',
    ]);
    $proposed = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactActionPath($fixture['workspace'], $customer, $deployment, $proposed, 'verify'))
        ->assertOk()
        ->assertJsonPath('data.status', ProjectFactStatus::Verified->value);

    expect($existing->fresh()->status)->toBe(ProjectFactStatus::Superseded)
        ->and($existing->fresh()->superseded_by_id)->toBe($proposed->id)
        ->and($proposed->fresh()->status)->toBe(ProjectFactStatus::Verified);
});

it('does not silently overwrite conflicting proposed facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 12',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsPath($fixture['workspace'], $customer, $deployment), [
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
    ])->assertCreated();

    expect(ProjectFact::query()->where('status', ProjectFactStatus::Proposed)->count())->toBe(2);
});

it('forbids viewers from creating or verifying facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(projectFactsPath($fixture['workspace'], $customer, $deployment), [
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
    ])->assertForbidden();

    $this->postJson(projectFactActionPath($fixture['workspace'], $customer, $deployment, $fact, 'verify'))
        ->assertForbidden();
});

it('enforces deployment scope bindings for project facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($customer)->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->getJson(projectFactsPath($fixture['workspace'], $customer, $otherDeployment, $fact))
        ->assertNotFound();
});

it('extracts proposed facts from active ready documentation without auto-verifying', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Architecture',
        'revision_number' => 2,
        'disk_path' => 'knowledge/test/architecture.txt',
        'original_filename' => 'architecture.txt',
        'mime_type' => 'text/plain',
    ]);
    Storage::disk('local')->put($document->disk_path, 'The backend framework is Laravel 13.');

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'architecture.txt',
                    'content' => 'The backend framework is Laravel 13.',
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::response(openAiFactExtractionResponse([
            [
                'category' => 'framework',
                'key' => 'backend',
                'value' => 'Laravel 13',
                'source_reference' => 'The backend framework is Laravel 13.',
                'confidence' => 0.92,
            ],
        ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document));
    $extraction = assertFinishedProjectFactExtraction($response, ProjectFactExtractionStatus::Completed, 1);

    $this->getJson(projectFactExtractionPath($fixture['workspace'], $customer, $deployment, $extraction))
        ->assertOk()
        ->assertJsonPath('data.status', ProjectFactExtractionStatus::Completed->value)
        ->assertJsonPath('data.proposed_count', 1)
        ->assertJsonPath('data.source_document_id', $document->id);

    $fact = ProjectFact::query()->first();
    expect($fact)->not->toBeNull()
        ->and($fact->status)->toBe(ProjectFactStatus::Proposed)
        ->and($fact->verified_at)->toBeNull()
        ->and($fact->source_document_id)->toBe($document->id)
        ->and($fact->source_revision)->toBe(2)
        ->and($fact->source_reference)->toBe('[Chunk 0] The backend framework is Laravel 13.')
        ->and($fact->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 0,
            'content_source' => 'ai_service_chunks',
        ]);

    Http::assertSent(function ($request): bool {
        $body = $request->data();
        $tool = is_array($body) ? ($body['tools'][0] ?? null) : null;
        $itemSchema = is_array($tool) ? ($tool['parameters']['properties']['facts']['items'] ?? null) : null;

        return is_array($body)
            && ($body['store'] ?? null) === false
            && ($tool['strict'] ?? null) === true
            && ($tool['parameters']['additionalProperties'] ?? null) === false
            && ($itemSchema['additionalProperties'] ?? null) === false
            && ($itemSchema['required'] ?? null) === [
                'category',
                'key',
                'value',
                'source_reference',
                'confidence',
                'source_chunk_index',
            ]
            && ($itemSchema['properties']['source_chunk_index']['type'] ?? null) === ['integer', 'null']
            && ($itemSchema['properties']['confidence']['type'] ?? null) === ['number', 'null'];
    });

    Http::assertSent(function ($request): bool {
        return $request->url() === 'http://ai-service.test/documents/chunks'
            && $request->hasHeader('X-AI-Service-Token', 'test-ai-service-token');
    });
});

it('extracts proposed facts from active ready pdf documentation using processed chunks', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(2)->active()->create([
        'title' => 'Architecture PDF',
        'revision_number' => 4,
        'disk_path' => 'knowledge/test/architecture.pdf',
        'original_filename' => 'architecture.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 1,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'The backend framework is Laravel 13.',
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::response(openAiFactExtractionResponse([
            [
                'category' => 'framework',
                'key' => 'backend',
                'value' => 'Laravel 13',
                'source_reference' => 'The backend framework is Laravel 13.',
                'source_chunk_index' => 1,
                'confidence' => 0.88,
            ],
        ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        1,
    );

    $fact = ProjectFact::query()->first();
    expect($fact)->not->toBeNull()
        ->and($fact->status)->toBe(ProjectFactStatus::Proposed)
        ->and($fact->source_document_id)->toBe($document->id)
        ->and($fact->source_revision)->toBe(4)
        ->and($fact->source_reference)->toBe('[Chunk 1] The backend framework is Laravel 13.')
        ->and($fact->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 1,
            'content_source' => 'ai_service_chunks',
        ]);

    Http::assertSent(function ($request) use ($document): bool {
        if ($request->url() !== 'http://ai-service.test/documents/chunks') {
            return false;
        }

        $body = $request->data();

        return ($body['workspace_id'] ?? null) === $document->workspace_id
            && ($body['customer_id'] ?? null) === $document->customer_id
            && ($body['deployment_id'] ?? null) === $document->deployment_id
            && ($body['document_id'] ?? null) === $document->id;
    });
});

it('extracts proposed facts from early and later chunks of the same document', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(4)->active()->create([
        'title' => 'Architecture PDF',
        'revision_number' => 6,
        'disk_path' => 'knowledge/test/architecture.pdf',
        'original_filename' => 'architecture.pdf',
        'mime_type' => 'application/pdf',
    ]);

    config([
        'services.project_facts.extraction_chunk_batch_size' => 2,
        'services.project_facts.extraction_max_batch_characters' => 12000,
    ]);

    $earlyContent = 'The backend framework is Laravel 13.';
    $laterContent = 'The primary database is PostgreSQL 16.';

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'architecture.pdf',
                    'content' => $earlyContent,
                ],
                [
                    'chunk_index' => 1,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'Table of contents and revision history.',
                ],
                [
                    'chunk_index' => 2,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'Deployment topology overview.',
                ],
                [
                    'chunk_index' => 3,
                    'source_filename' => 'architecture.pdf',
                    'content' => $laterContent,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'framework',
                    'key' => 'backend',
                    'value' => 'Laravel 13',
                    'source_reference' => $earlyContent,
                    'source_chunk_index' => 0,
                    'confidence' => 0.94,
                ],
            ]))
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'database',
                    'key' => 'primary',
                    'value' => 'PostgreSQL 16',
                    'source_reference' => $laterContent,
                    'source_chunk_index' => 3,
                    'confidence' => 0.91,
                ],
            ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        2,
    );

    $facts = ProjectFact::query()->orderBy('id')->get();

    expect($facts)->toHaveCount(2)
        ->and($facts->every(fn (ProjectFact $fact): bool => $fact->status === ProjectFactStatus::Proposed))->toBeTrue()
        ->and($facts->firstWhere('key', 'backend'))->not->toBeNull()
        ->and($facts->firstWhere('key', 'backend')?->source_reference)->toBe('[Chunk 0] '.$earlyContent)
        ->and($facts->firstWhere('key', 'backend')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 0,
            'content_source' => 'ai_service_chunks',
        ])
        ->and($facts->firstWhere('key', 'primary'))->not->toBeNull()
        ->and($facts->firstWhere('key', 'primary')?->source_reference)->toBe('[Chunk 3] '.$laterContent)
        ->and($facts->firstWhere('key', 'primary')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 3,
            'content_source' => 'ai_service_chunks',
        ]);

    $openAiRequests = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request): bool => str_contains($request->url(), 'api.openai.com/v1/responses'))
        ->values();

    expect($openAiRequests)->toHaveCount(2)
        ->and(openaiExtractionInputText($openAiRequests[0]))->toContain('[Chunk 0]')->toContain($earlyContent)->not->toContain('[Chunk 3]')
        ->and(openaiExtractionInputText($openAiRequests[1]))->toContain('[Chunk 3]')->toContain($laterContent)->not->toContain('[Chunk 0]');

    foreach ($openAiRequests as $request) {
        $body = $request->data();

        expect($body['store'] ?? null)->toBeFalse()
            ->and($body['max_output_tokens'] ?? null)->toBe(4096);
    }

    Http::assertSent(function ($request) use ($document): bool {
        if ($request->url() !== 'http://ai-service.test/documents/chunks') {
            return false;
        }

        $body = $request->data();

        return ($body['workspace_id'] ?? null) === $document->workspace_id
            && ($body['customer_id'] ?? null) === $document->customer_id
            && ($body['deployment_id'] ?? null) === $document->deployment_id
            && ($body['document_id'] ?? null) === $document->id;
    });
});

it('merges equivalent facts extracted from different batches', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(4)->active()->create([
        'disk_path' => 'knowledge/test/architecture.pdf',
        'original_filename' => 'architecture.pdf',
        'mime_type' => 'application/pdf',
    ]);

    config([
        'services.project_facts.extraction_chunk_batch_size' => 2,
    ]);

    $earlyContent = 'The backend framework is Laravel 13.';
    $laterContent = 'Backend framework: Laravel 13 across all environments.';

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'architecture.pdf',
                    'content' => $earlyContent,
                ],
                [
                    'chunk_index' => 1,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'Revision history.',
                ],
                [
                    'chunk_index' => 2,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'Operations notes.',
                ],
                [
                    'chunk_index' => 3,
                    'source_filename' => 'architecture.pdf',
                    'content' => $laterContent,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'framework',
                    'key' => 'backend',
                    'value' => 'Laravel 13',
                    'source_reference' => $earlyContent,
                    'source_chunk_index' => 0,
                    'confidence' => 0.82,
                ],
            ]))
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'framework',
                    'key' => 'backend',
                    'value' => 'Laravel 13',
                    'source_reference' => 'Backend framework: Laravel 13 across all environments.',
                    'source_chunk_index' => 3,
                    'confidence' => 0.96,
                ],
            ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        1,
    );

    $fact = ProjectFact::query()->first();
    expect($fact)->not->toBeNull()
        ->and($fact?->status)->toBe(ProjectFactStatus::Proposed)
        ->and($fact?->value)->toBe('Laravel 13')
        ->and($fact?->source_reference)->toBe('[Chunk 3] Backend framework: Laravel 13 across all environments.')
        ->and($fact?->confidence)->toBe(0.96)
        ->and($fact?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 3,
        ]);
});

it('drops invented and low-confidence extracted facts and caps the remainder', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(2)->active()->create([
        'disk_path' => 'knowledge/test/architecture.pdf',
        'original_filename' => 'architecture.pdf',
        'mime_type' => 'application/pdf',
    ]);

    config([
        'services.project_facts.extraction_max_facts' => 2,
        'services.project_facts.extraction_min_confidence' => 0.7,
        'services.project_facts.extraction_chunk_batch_size' => 8,
    ]);

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'The backend framework is Laravel 13. Authorization uses Sanctum tokens.',
                ],
                [
                    'chunk_index' => 1,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'The primary database is PostgreSQL 16. Cache is Redis.',
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::response(openAiFactExtractionResponse([
            [
                'category' => 'framework',
                'key' => 'backend',
                'value' => 'Laravel 13',
                'source_reference' => 'The backend framework is Laravel 13.',
                'source_chunk_index' => 0,
                'confidence' => 0.99,
            ],
            [
                'category' => 'authorization',
                'key' => 'model',
                'value' => 'Sanctum',
                'source_reference' => 'Authorization uses Sanctum tokens.',
                'source_chunk_index' => 0,
                'confidence' => 0.81,
            ],
            [
                'category' => 'database',
                'key' => 'primary',
                'value' => 'PostgreSQL 16',
                'source_reference' => 'The primary database is PostgreSQL 16.',
                'source_chunk_index' => 1,
                'confidence' => 0.93,
            ],
            [
                'category' => 'cache',
                'key' => 'driver',
                'value' => 'Redis',
                'source_reference' => 'Cache is Redis.',
                'source_chunk_index' => 1,
                'confidence' => 0.4,
            ],
            [
                'category' => 'infrastructure',
                'key' => 'orchestrator',
                'value' => 'Kubernetes',
                'source_reference' => 'The platform runs on Kubernetes.',
                'source_chunk_index' => 1,
                'confidence' => 0.98,
            ],
        ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        2,
    );

    $facts = ProjectFact::query()->get();

    expect($facts)->toHaveCount(2)
        ->and($facts->pluck('key')->sort()->values()->all())->toBe(['backend', 'primary'])
        ->and($facts->every(fn (ProjectFact $fact): bool => $fact->status === ProjectFactStatus::Proposed))->toBeTrue()
        ->and($facts->contains(fn (ProjectFact $fact): bool => $fact->key === 'driver'))->toBeFalse()
        ->and($facts->contains(fn (ProjectFact $fact): bool => $fact->value === 'Kubernetes'))->toBeFalse();
});

it('keeps high-value later-chunk facts after deduplicating and ranking before the cap', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(4)->active()->create([
        'title' => 'Movier Requisitos',
        'revision_number' => 7,
        'disk_path' => 'knowledge/test/movier-requisitos.pdf',
        'original_filename' => 'movier-requisitos.pdf',
        'mime_type' => 'application/pdf',
    ]);

    config([
        'services.project_facts.extraction_max_facts' => 3,
        'services.project_facts.extraction_min_confidence' => 0.7,
        'services.project_facts.extraction_chunk_batch_size' => 2,
        'services.project_facts.extraction_max_batch_characters' => 12000,
    ]);

    $subcategories = ['Action', 'Comedy', 'Drama', 'Horror', 'Romance', 'Thriller', 'Documentary', 'Animation'];
    $catalogContent = implode(' ', array_map(
        fn (string $name): string => "Catalog subcategory: {$name}.",
        $subcategories,
    ));
    $timeoutContent = 'The payment provider timeout is 30 seconds.';
    $authorizationContent = 'Only administrators can approve refunds.';
    $workflowContent = 'Orders transition from pending to paid after settlement.';

    $catalogFacts = array_map(
        fn (string $name): array => [
            'category' => 'catalog',
            'key' => 'subcategory.'.strtolower($name),
            'value' => $name,
            'source_reference' => "Catalog subcategory: {$name}.",
            'source_chunk_index' => 0,
            'confidence' => 0.99,
        ],
        $subcategories,
    );

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $catalogContent,
                ],
                [
                    'chunk_index' => 1,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => 'Catalog subcategory: Action. Genre examples and sample listings.',
                ],
                [
                    'chunk_index' => 8,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $timeoutContent.' '.$authorizationContent,
                ],
                [
                    'chunk_index' => 9,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $workflowContent,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiFactExtractionResponse([
                ...$catalogFacts,
                [
                    'category' => 'catalog',
                    'key' => 'subcategory.action',
                    'value' => 'Action',
                    'source_reference' => 'Catalog subcategory: Action.',
                    'source_chunk_index' => 1,
                    'confidence' => 0.98,
                ],
            ]))
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'integration',
                    'key' => 'payment.timeout_seconds',
                    'value' => '30',
                    'source_reference' => $timeoutContent,
                    'source_chunk_index' => 8,
                    'confidence' => 0.81,
                ],
                [
                    'category' => 'authorization',
                    'key' => 'refund.approver',
                    'value' => 'Only administrators can approve refunds',
                    'source_reference' => $authorizationContent,
                    'source_chunk_index' => 8,
                    'confidence' => 0.8,
                ],
                [
                    'category' => 'workflow',
                    'key' => 'order.paid_transition',
                    'value' => 'Orders transition from pending to paid after settlement',
                    'source_reference' => $workflowContent,
                    'source_chunk_index' => 9,
                    'confidence' => 0.78,
                ],
            ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        3,
    );

    $facts = ProjectFact::query()->orderBy('id')->get();

    expect($facts)->toHaveCount(3)
        ->and($facts->every(fn (ProjectFact $fact): bool => $fact->status === ProjectFactStatus::Proposed))->toBeTrue()
        ->and($facts->pluck('key')->sort()->values()->all())->toBe([
            'order.paid_transition',
            'payment.timeout_seconds',
            'refund.approver',
        ])
        ->and($facts->firstWhere('key', 'payment.timeout_seconds')?->source_reference)->toBe('[Chunk 8] '.$timeoutContent)
        ->and($facts->firstWhere('key', 'payment.timeout_seconds')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 8,
            'content_source' => 'ai_service_chunks',
        ])
        ->and($facts->firstWhere('key', 'refund.approver')?->source_reference)->toBe('[Chunk 8] '.$authorizationContent)
        ->and($facts->firstWhere('key', 'refund.approver')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 8,
            'content_source' => 'ai_service_chunks',
        ])
        ->and($facts->firstWhere('key', 'order.paid_transition')?->source_reference)->toBe('[Chunk 9] '.$workflowContent)
        ->and($facts->firstWhere('key', 'order.paid_transition')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 9,
            'content_source' => 'ai_service_chunks',
        ])
        ->and($facts->contains(fn (ProjectFact $fact): bool => str_contains($fact->key, 'subcategory')))->toBeFalse();

    $openAiRequests = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request): bool => str_contains($request->url(), 'api.openai.com/v1/responses'))
        ->values();

    expect($openAiRequests)->toHaveCount(2)
        ->and(openaiExtractionInputText($openAiRequests[0]))->toContain('[Chunk 0]')->toContain($catalogContent)->not->toContain('[Chunk 8]')
        ->and(openaiExtractionInputText($openAiRequests[1]))->toContain('[Chunk 8]')->toContain($timeoutContent)->toContain('[Chunk 9]')->not->toContain('[Chunk 0]');

    foreach ($openAiRequests as $request) {
        $instructions = (string) ($request->data()['instructions'] ?? '');

        expect($instructions)->toContain('Prioritize facts useful for engineering, business rules, integrations, architecture, and operational behavior.')
            ->toContain('Deprioritize exhaustive taxonomy or catalog list items')
            ->toContain('Facts are proposals only')
            ->toContain('Do not invent');
    }
});

it('keeps distinct scoped reservation rules ahead of logistics details within the fact cap', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(4)->active()->create([
        'title' => 'Movier Requisitos',
        'revision_number' => 8,
        'disk_path' => 'knowledge/test/movier-requisitos.pdf',
        'original_filename' => 'movier-requisitos.pdf',
        'mime_type' => 'application/pdf',
    ]);

    config([
        'services.project_facts.extraction_max_facts' => 4,
        'services.project_facts.extraction_min_confidence' => 0.7,
        'services.project_facts.extraction_chunk_batch_size' => 2,
        'services.project_facts.extraction_max_batch_characters' => 12000,
    ]);

    $couriers = ['DHL', 'FedEx', 'UPS', 'Estafeta', 'Correos', 'DHL Express', 'FedEx Ground', 'UPS Saver'];
    $logisticsContent = implode(' ', array_map(
        fn (string $name): string => "Shipping courier: {$name}.",
        $couriers,
    ));
    $whatsappContent = 'WhatsApp reservations are held for 1 week.';
    $cartTimeoutContent = 'Cart reservation lasts 15 minutes.';
    $cartReleaseContent = 'If the user does not pay, the cart reservation is automatically released.';
    $priorityContent = 'When multiple customers want the same product, the first confirmed payment receives reservation priority.';

    $logisticsFacts = array_map(
        fn (string $name): array => [
            'category' => 'logistics',
            'key' => 'shipping.courier.'.Str::slug($name, '_'),
            'value' => $name,
            'source_reference' => "Shipping courier: {$name}.",
            'source_chunk_index' => 0,
            'confidence' => 0.99,
        ],
        $couriers,
    );

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $logisticsContent,
                ],
                [
                    'chunk_index' => 1,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $whatsappContent.' Packaging warehouse hours and courier pickup windows.',
                ],
                [
                    'chunk_index' => 8,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $cartTimeoutContent.' '.$cartReleaseContent,
                ],
                [
                    'chunk_index' => 9,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $priorityContent,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiFactExtractionResponse([
                ...$logisticsFacts,
                [
                    'category' => 'reservation',
                    'key' => 'reservation.duration',
                    'value' => '1 week',
                    'source_reference' => $whatsappContent,
                    'source_chunk_index' => 1,
                    'confidence' => 0.97,
                ],
            ]))
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'reservation',
                    'key' => 'reservation.timeout',
                    'value' => '15 minutes',
                    'source_reference' => $cartTimeoutContent,
                    'source_chunk_index' => 8,
                    'confidence' => 0.78,
                ],
                [
                    'category' => 'reservation',
                    'key' => 'reservation.release',
                    'value' => 'automatic release',
                    'source_reference' => $cartReleaseContent,
                    'source_chunk_index' => 8,
                    'confidence' => 0.76,
                ],
                [
                    'category' => 'reservation',
                    'key' => 'reservation.priority',
                    'value' => 'first confirmed payment',
                    'source_reference' => $priorityContent,
                    'source_chunk_index' => 9,
                    'confidence' => 0.74,
                ],
            ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        4,
    );

    $facts = ProjectFact::query()->orderBy('id')->get();

    expect($facts)->toHaveCount(4)
        ->and($facts->every(fn (ProjectFact $fact): bool => $fact->status === ProjectFactStatus::Proposed))->toBeTrue()
        ->and($facts->pluck('key')->sort()->values()->all())->toBe([
            'reservation.cart.expiration_behavior',
            'reservation.cart.timeout',
            'reservation.conflict.priority',
            'reservation.whatsapp.hold_duration',
        ])
        ->and($facts->firstWhere('key', 'reservation.cart.timeout')?->value)->toBe('15 minutes')
        ->and($facts->firstWhere('key', 'reservation.cart.timeout')?->source_reference)->toBe('[Chunk 8] '.$cartTimeoutContent)
        ->and($facts->firstWhere('key', 'reservation.cart.timeout')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 8,
            'content_source' => 'ai_service_chunks',
        ])
        ->and($facts->firstWhere('key', 'reservation.cart.expiration_behavior')?->value)->toBe('automatic release')
        ->and($facts->firstWhere('key', 'reservation.cart.expiration_behavior')?->source_reference)->toBe('[Chunk 8] '.$cartReleaseContent)
        ->and($facts->firstWhere('key', 'reservation.cart.expiration_behavior')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 8,
            'content_source' => 'ai_service_chunks',
        ])
        ->and($facts->firstWhere('key', 'reservation.whatsapp.hold_duration')?->value)->toBe('1 week')
        ->and($facts->firstWhere('key', 'reservation.whatsapp.hold_duration')?->source_reference)->toBe('[Chunk 1] '.$whatsappContent)
        ->and($facts->firstWhere('key', 'reservation.whatsapp.hold_duration')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 1,
            'content_source' => 'ai_service_chunks',
        ])
        ->and($facts->firstWhere('key', 'reservation.conflict.priority')?->value)->toBe('first confirmed payment')
        ->and($facts->firstWhere('key', 'reservation.conflict.priority')?->source_reference)->toBe('[Chunk 9] '.$priorityContent)
        ->and($facts->firstWhere('key', 'reservation.conflict.priority')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 9,
            'content_source' => 'ai_service_chunks',
        ])
        ->and($facts->contains(fn (ProjectFact $fact): bool => str_contains($fact->key, 'shipping')))->toBeFalse();

    $openAiRequests = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request): bool => str_contains($request->url(), 'api.openai.com/v1/responses'))
        ->values();

    expect($openAiRequests)->toHaveCount(2)
        ->and(openaiExtractionInputText($openAiRequests[0]))->toContain('[Chunk 0]')->toContain($logisticsContent)->toContain($whatsappContent)->not->toContain('[Chunk 8]')
        ->and(openaiExtractionInputText($openAiRequests[1]))->toContain('[Chunk 8]')->toContain($cartTimeoutContent)->toContain('[Chunk 9]')->not->toContain('[Chunk 0]');

    foreach ($openAiRequests as $request) {
        $instructions = (string) ($request->data()['instructions'] ?? '');

        expect($instructions)->toContain('Preserve workflow scope in keys')
            ->toContain('Cart checkout reservations and WhatsApp holds are different workflows')
            ->toContain('reservation.cart.timeout')
            ->toContain('reservation.whatsapp.hold_duration')
            ->toContain('reservation.conflict.priority')
            ->toContain('Deprioritize numerous logistics')
            ->toContain('Facts are proposals only');
    }
});

it('fails pdf fact extraction when processed chunks are unavailable', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(2)->active()->create([
        'disk_path' => 'knowledge/test/architecture.pdf',
        'original_filename' => 'architecture.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response(['chunks' => []]),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $extraction = assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Failed,
    );

    expect($extraction->error_message)->toBe('Processed document content is unavailable for fact extraction.')
        ->and(ProjectFact::query()->count())->toBe(0);
});

it('extracts proposed facts from txt documentation without processed chunks using local content', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(0)->active()->create([
        'title' => 'Architecture',
        'revision_number' => 1,
        'disk_path' => 'knowledge/test/architecture.txt',
        'original_filename' => 'architecture.txt',
        'mime_type' => 'text/plain',
        'chunk_count' => 0,
    ]);
    Storage::disk('local')->put($document->disk_path, 'The database primary store is MySQL.');

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(openAiFactExtractionResponse([
            [
                'category' => 'database',
                'key' => 'primary',
                'value' => 'MySQL',
                'source_reference' => 'The database primary store is MySQL.',
                'confidence' => 0.9,
            ],
        ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        1,
    );

    Http::assertNotSent(function ($request): bool {
        return $request->url() === 'http://ai-service.test/documents/chunks';
    });
});

it('keeps valid facts when a batch contains malformed items', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(2)->active()->create([
        'title' => 'Movier Requisitos',
        'revision_number' => 2,
        'disk_path' => 'knowledge/test/movier-requisitos.pdf',
        'original_filename' => 'movier-requisitos.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $backendContent = 'The backend framework is Laravel 13.';
    $databaseContent = 'The primary database is PostgreSQL 16.';

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $backendContent,
                ],
                [
                    'chunk_index' => 1,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $databaseContent,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::response(openAiFactExtractionResponse([
            'not-an-object',
            [
                'category' => 'framework',
                'key' => 'backend',
                'value' => 'Laravel 13',
                'source_reference' => $backendContent,
                'source_chunk_index' => '0',
                'confidence' => 0.94,
            ],
            [
                'category' => ['invalid'],
                'key' => 'ignored',
                'value' => 'should skip',
                'source_reference' => $backendContent,
            ],
            [
                'category' => 'database',
                'key' => 'primary',
                'value' => 'PostgreSQL 16',
                'source_reference' => $databaseContent,
                'source_chunk_index' => null,
                'confidence' => '0.91',
            ],
        ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        2,
    );

    $facts = ProjectFact::query()->orderBy('id')->get();

    expect($facts)->toHaveCount(2)
        ->and($facts->every(fn (ProjectFact $fact): bool => $fact->status === ProjectFactStatus::Proposed))->toBeTrue()
        ->and($facts->firstWhere('key', 'backend')?->source_reference)->toBe('[Chunk 0] '.$backendContent)
        ->and($facts->firstWhere('key', 'backend')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 0,
        ])
        ->and($facts->firstWhere('key', 'primary')?->source_reference)->toBe('[Chunk 1] '.$databaseContent)
        ->and($facts->firstWhere('key', 'primary')?->extraction_metadata)->toMatchArray([
            'source_chunk_index' => 1,
        ]);
});

it('retries an invalid extraction batch once without creating duplicate facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(4)->active()->create([
        'title' => 'Movier Requisitos',
        'revision_number' => 2,
        'disk_path' => 'knowledge/test/movier-requisitos.pdf',
        'original_filename' => 'movier-requisitos.pdf',
        'mime_type' => 'application/pdf',
    ]);

    config([
        'services.project_facts.extraction_chunk_batch_size' => 2,
    ]);

    $earlyContent = 'The backend framework is Laravel 13.';
    $laterContent = 'The primary database is PostgreSQL 16.';

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $earlyContent,
                ],
                [
                    'chunk_index' => 1,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => 'Revision history.',
                ],
                [
                    'chunk_index' => 2,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => 'Operations notes.',
                ],
                [
                    'chunk_index' => 3,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $laterContent,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'framework',
                    'key' => 'backend',
                    'value' => 'Laravel 13',
                    'source_reference' => $earlyContent,
                    'source_chunk_index' => 0,
                    'confidence' => 0.94,
                ],
            ]))
            ->push(openAiToolCallResponse(
                'propose_project_facts',
                '{"facts":[{"category":"database","key":"primary","value":"PostgreSQL 16","source_reference":"The primary database is PostgreSQL 16."',
            ))
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'database',
                    'key' => 'primary',
                    'value' => 'PostgreSQL 16',
                    'source_reference' => $laterContent,
                    'source_chunk_index' => 3,
                    'confidence' => 0.91,
                ],
            ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        2,
    );

    $facts = ProjectFact::query()->orderBy('id')->get();

    expect($facts)->toHaveCount(2)
        ->and($facts->every(fn (ProjectFact $fact): bool => $fact->status === ProjectFactStatus::Proposed))->toBeTrue()
        ->and($facts->firstWhere('key', 'backend'))->not->toBeNull()
        ->and($facts->firstWhere('key', 'primary'))->not->toBeNull();

    $openAiRequests = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request): bool => str_contains($request->url(), 'api.openai.com/v1/responses'))
        ->values();

    expect($openAiRequests)->toHaveCount(3)
        ->and(openaiExtractionInputText($openAiRequests[1]))->toBe(openaiExtractionInputText($openAiRequests[2]))
        ->and(openaiExtractionInputText($openAiRequests[1]))->toContain('[Chunk 3]')->not->toContain('[Chunk 0]');
});

it('returns a logged batch error when extraction output remains invalid after retry', function () {
    Log::spy();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(2)->active()->create([
        'title' => 'Movier Requisitos',
        'revision_number' => 2,
        'disk_path' => 'knowledge/test/movier-requisitos.pdf',
        'original_filename' => 'movier-requisitos.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $secretContent = 'CONFIDENTIAL-REQUIREMENT-QUOTE: "El sistema debe autenticar usuarios".';

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $secretContent,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse(
                'propose_project_facts',
                '{"facts":[{"category":"security","key":"auth","value":"required","source_reference":"'.$secretContent,
            ))
            ->push(openAiToolCallResponse(
                'propose_project_facts',
                '{"facts":[{"category":"security","key":"auth","value":"required","source_reference":"'.$secretContent,
            )),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $extraction = assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Failed,
    );

    expect($extraction->error_message)->toBe('The AI service returned invalid extraction output for batch 1 of 1.')
        ->and(ProjectFact::query()->count())->toBe(0);

    $this->getJson(projectFactExtractionPath($fixture['workspace'], $customer, $deployment, $extraction))
        ->assertOk()
        ->assertJsonPath('data.status', ProjectFactExtractionStatus::Failed->value)
        ->assertJsonPath('data.error_message', 'The AI service returned invalid extraction output for batch 1 of 1.');

    Log::shouldHaveReceived('warning')
        ->twice()
        ->with('Project fact extraction batch failed.', Mockery::on(function (array $context) use ($secretContent): bool {
            $encoded = json_encode($context);

            return ($context['batch_number'] ?? null) === 1
                && ($context['batch_count'] ?? null) === 1
                && ($context['reason'] ?? null) === 'invalid_json'
                && in_array($context['attempt'] ?? null, [1, 2], true)
                && ! str_contains((string) $encoded, $secretContent)
                && ! str_contains((string) $encoded, 'El sistema debe autenticar usuarios')
                && ! array_key_exists('arguments', $context)
                && ! array_key_exists('document_content', $context);
        }));
});

it('does not recreate equivalent proposed or verified facts on repeated extraction', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(2)->active()->create([
        'title' => 'Movier Requisitos',
        'revision_number' => 2,
        'disk_path' => 'knowledge/test/movier-requisitos.pdf',
        'original_filename' => 'movier-requisitos.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $backendContent = 'The backend framework is Laravel 13.';
    $databaseContent = 'The primary database is PostgreSQL 16.';

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $backendContent,
                ],
                [
                    'chunk_index' => 1,
                    'source_filename' => 'movier-requisitos.pdf',
                    'content' => $databaseContent,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::response(openAiFactExtractionResponse([
            [
                'category' => 'framework',
                'key' => 'backend',
                'value' => 'Laravel 13',
                'source_reference' => $backendContent,
                'source_chunk_index' => 0,
                'confidence' => 0.94,
            ],
            [
                'category' => 'database',
                'key' => 'primary',
                'value' => 'PostgreSQL 16',
                'source_reference' => $databaseContent,
                'source_chunk_index' => 1,
                'confidence' => 0.91,
            ],
        ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        2,
    );

    $backend = ProjectFact::query()->where('key', 'backend')->first();
    expect($backend)->not->toBeNull();

    $this->postJson(projectFactActionPath($fixture['workspace'], $customer, $deployment, $backend, 'verify'))
        ->assertOk();

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        0,
    );

    expect(ProjectFact::query()->count())->toBe(2)
        ->and(ProjectFact::query()->where('status', ProjectFactStatus::Proposed)->count())->toBe(1)
        ->and(ProjectFact::query()->where('status', ProjectFactStatus::Verified)->count())->toBe(1);
});

it('rejects fact extraction from draft documentation', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->draft()->create([
        'disk_path' => 'knowledge/test/draft.txt',
    ]);
    Storage::disk('local')->put($document->disk_path, 'Draft content.');

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Facts can only be extracted from active, ready project documentation.');

    expect(ProjectFact::query()->count())->toBe(0)
        ->and(ProjectFactExtraction::query()->count())->toBe(0);
});

it('enqueues fact extraction and exposes pending processing completed status', function () {
    Queue::fake();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Architecture',
        'revision_number' => 2,
        'disk_path' => 'knowledge/test/architecture.txt',
        'original_filename' => 'architecture.txt',
        'mime_type' => 'text/plain',
    ]);
    Storage::disk('local')->put($document->disk_path, 'The backend framework is Laravel 13.');

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'architecture.txt',
                    'content' => 'The backend framework is Laravel 13.',
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::response(openAiFactExtractionResponse([
            [
                'category' => 'framework',
                'key' => 'backend',
                'value' => 'Laravel 13',
                'source_reference' => 'The backend framework is Laravel 13.',
                'confidence' => 0.92,
            ],
        ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertAccepted()
        ->assertJsonPath('data.status', ProjectFactExtractionStatus::Pending->value);

    $extraction = ProjectFactExtraction::query()->findOrFail($response->json('data.id'));

    expect($extraction->status)->toBe(ProjectFactExtractionStatus::Pending)
        ->and(ProjectFact::query()->count())->toBe(0);

    Queue::assertPushed(ExtractProjectFactsJob::class, 1);

    $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertAccepted()
        ->assertJsonPath('data.id', $extraction->id);

    Queue::assertPushed(ExtractProjectFactsJob::class, 1);

    $this->getJson(projectFactExtractionPath($fixture['workspace'], $customer, $deployment, $extraction))
        ->assertOk()
        ->assertJsonPath('data.status', ProjectFactExtractionStatus::Pending->value);

    runExtractProjectFactsJob($extraction);

    $this->getJson(projectFactExtractionPath($fixture['workspace'], $customer, $deployment, $extraction))
        ->assertOk()
        ->assertJsonPath('data.status', ProjectFactExtractionStatus::Completed->value)
        ->assertJsonPath('data.proposed_count', 1);

    expect(ProjectFact::query()->count())->toBe(1)
        ->and(ProjectFact::query()->first()?->status)->toBe(ProjectFactStatus::Proposed);
});

it('retries a truncated incomplete extraction batch once without duplicating facts', function () {
    Log::spy();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(2)->active()->create([
        'disk_path' => 'knowledge/test/architecture.pdf',
        'original_filename' => 'architecture.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $content = 'The backend framework is Laravel 13.';

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'architecture.pdf',
                    'content' => $content,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiIncompleteToolCallResponse(
                'propose_project_facts',
                '{"facts":[{"category":"framework","key":"backend","value":"Laravel 13","source_reference":"The backend framework is Laravel 13."',
            ))
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'framework',
                    'key' => 'backend',
                    'value' => 'Laravel 13',
                    'source_reference' => $content,
                    'source_chunk_index' => 0,
                    'confidence' => 0.94,
                ],
            ])),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Completed,
        1,
    );

    expect(ProjectFact::query()->count())->toBe(1)
        ->and(ProjectFact::query()->first()?->key)->toBe('backend');

    $openAiRequests = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request): bool => str_contains($request->url(), 'api.openai.com/v1/responses'))
        ->values();

    expect($openAiRequests)->toHaveCount(2)
        ->and(openaiExtractionInputText($openAiRequests[0]))->toBe(openaiExtractionInputText($openAiRequests[1]));

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Project fact extraction batch failed.', Mockery::on(function (array $context): bool {
            return ($context['attempt'] ?? null) === 1
                && ($context['reason'] ?? null) === 'incomplete_output'
                && ($context['incomplete_reason'] ?? null) === 'max_output_tokens';
        }));
});

it('marks the extraction failed without persisting facts when the job cannot complete', function () {
    Queue::fake();

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(2)->active()->create([
        'disk_path' => 'knowledge/test/architecture.pdf',
        'original_filename' => 'architecture.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'The backend framework is Laravel 13.',
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiIncompleteToolCallResponse('propose_project_facts'))
            ->push(openAiIncompleteToolCallResponse('propose_project_facts')),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertAccepted()
        ->assertJsonPath('data.status', ProjectFactExtractionStatus::Pending->value);

    $extraction = ProjectFactExtraction::query()->findOrFail($response->json('data.id'));

    expect($extraction->status)->toBe(ProjectFactExtractionStatus::Pending)
        ->and(ProjectFact::query()->count())->toBe(0);

    runExtractProjectFactsJob($extraction);

    $extraction = $extraction->fresh();

    expect($extraction?->status)->toBe(ProjectFactExtractionStatus::Failed)
        ->and($extraction?->error_message)->toBe('The AI service returned invalid extraction output for batch 1 of 1.')
        ->and(ProjectFact::query()->count())->toBe(0);

    $this->getJson(projectFactExtractionPath($fixture['workspace'], $customer, $deployment, $extraction))
        ->assertOk()
        ->assertJsonPath('data.status', ProjectFactExtractionStatus::Failed->value)
        ->assertJsonPath('data.error_message', 'The AI service returned invalid extraction output for batch 1 of 1.');
});

it('does not persist facts from earlier batches when a later batch fails after retry', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready(4)->active()->create([
        'disk_path' => 'knowledge/test/architecture.pdf',
        'original_filename' => 'architecture.pdf',
        'mime_type' => 'application/pdf',
    ]);

    config([
        'services.project_facts.extraction_chunk_batch_size' => 2,
    ]);

    $earlyContent = 'The backend framework is Laravel 13.';
    $laterContent = 'The primary database is PostgreSQL 16.';

    Http::fake([
        'http://ai-service.test/documents/chunks' => Http::response([
            'chunks' => [
                [
                    'chunk_index' => 0,
                    'source_filename' => 'architecture.pdf',
                    'content' => $earlyContent,
                ],
                [
                    'chunk_index' => 1,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'Revision history.',
                ],
                [
                    'chunk_index' => 2,
                    'source_filename' => 'architecture.pdf',
                    'content' => 'Operations notes.',
                ],
                [
                    'chunk_index' => 3,
                    'source_filename' => 'architecture.pdf',
                    'content' => $laterContent,
                ],
            ],
        ]),
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiFactExtractionResponse([
                [
                    'category' => 'framework',
                    'key' => 'backend',
                    'value' => 'Laravel 13',
                    'source_reference' => $earlyContent,
                    'source_chunk_index' => 0,
                    'confidence' => 0.94,
                ],
            ]))
            ->push(openAiIncompleteToolCallResponse('propose_project_facts'))
            ->push(openAiIncompleteToolCallResponse('propose_project_facts')),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $extraction = assertFinishedProjectFactExtraction(
        $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document)),
        ProjectFactExtractionStatus::Failed,
    );

    expect($extraction->error_message)->toBe('The AI service returned invalid extraction output for batch 2 of 2.')
        ->and(ProjectFact::query()->count())->toBe(0);
});

it('forbids viewers from enqueueing or polling fact extraction', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();
    $extraction = ProjectFactExtraction::factory()->forDocument($document, $fixture['engineer'])->pending()->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(extractFactsPath($fixture['workspace'], $customer, $deployment, $document))
        ->assertForbidden();

    $this->getJson(projectFactExtractionPath($fixture['workspace'], $customer, $deployment, $extraction))
        ->assertForbidden();
});

it('enforces deployment isolation for extraction status', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();
    $extraction = ProjectFactExtraction::factory()->forDocument($document, $fixture['engineer'])->pending()->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->getJson(projectFactExtractionPath($fixture['workspace'], $customer, $otherDeployment, $extraction))
        ->assertNotFound();
});

it('allows authorized users to edit proposed facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'value' => 'Laravel 12',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->patchJson(projectFactsPath($fixture['workspace'], $customer, $deployment, $fact), [
        'value' => 'Laravel 13',
    ])
        ->assertOk()
        ->assertJsonPath('data.value', 'Laravel 13');
});

it('forbids editing verified facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->patchJson(projectFactsPath($fixture['workspace'], $customer, $deployment, $fact), [
        'value' => 'Changed',
    ])->assertForbidden();
});

it('allows engineers to bulk verify explicitly selected proposed facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $first = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
    ]);
    $second = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'database',
        'key' => 'primary',
        'value' => 'PostgreSQL 16',
    ]);
    $untouched = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'cache',
        'key' => 'driver',
        'value' => 'Redis',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-verify'), [
        'ids' => [$first->id, $second->id],
    ])
        ->assertOk()
        ->assertJsonPath('processed_count', 2)
        ->assertJsonPath('stats.proposed_count', 1)
        ->assertJsonPath('stats.verified_count', 2);

    expect($first->fresh()->status)->toBe(ProjectFactStatus::Verified)
        ->and($second->fresh()->status)->toBe(ProjectFactStatus::Verified)
        ->and($untouched->fresh()->status)->toBe(ProjectFactStatus::Proposed)
        ->and($first->fresh()->verified_by)->toBe($fixture['engineer']->id);
});

it('requires explicit selected ids for bulk verify and rejects empty or source-scoped approve all', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->fromDocument($document)->proposed()->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-verify'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ids']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-verify'), [
        'ids' => [],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ids']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-verify'), [
        'source_document_id' => $document->id,
        'source_revision' => $document->revision_number,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ids']);

    expect($fact->fresh()->status)->toBe(ProjectFactStatus::Proposed);
});

it('bulk rejects selected proposed facts without deleting history', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $first = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'key' => 'backend',
    ]);
    $second = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'key' => 'primary',
        'category' => 'database',
        'value' => 'PostgreSQL 16',
    ]);

    Sanctum::actingAs($fixture['admin']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-reject'), [
        'ids' => [$first->id, $second->id],
    ])
        ->assertOk()
        ->assertJsonPath('processed_count', 2)
        ->assertJsonPath('stats.rejected_count', 2)
        ->assertJsonPath('stats.proposed_count', 0);

    expect(ProjectFact::query()->count())->toBe(2)
        ->and($first->fresh()->status)->toBe(ProjectFactStatus::Rejected)
        ->and($second->fresh()->status)->toBe(ProjectFactStatus::Rejected)
        ->and($first->fresh()->verified_by)->toBe($fixture['admin']->id)
        ->and($first->fresh()->verified_at)->not->toBeNull();
});

it('bulk rejects all proposed facts from a source document revision', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Architecture',
        'revision_number' => 4,
    ]);
    $otherDocument = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Runbook',
        'revision_number' => 1,
    ]);
    $fromDocument = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->fromDocument($document)->proposed()->count(3)->create();
    $otherRevision = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->fromDocument($document)->proposed()->create([
        'source_revision' => 3,
        'key' => 'older',
    ]);
    $fromOtherDocument = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->fromDocument($otherDocument)->proposed()->create([
        'key' => 'runbook',
    ]);
    $verified = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->fromDocument($document)->verified($fixture['admin'])->create([
        'key' => 'already-verified',
        'value' => 'Keep me',
    ]);
    $manual = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'key' => 'manual',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-reject'), [
        'source_document_id' => $document->id,
        'source_revision' => 4,
    ])
        ->assertOk()
        ->assertJsonPath('processed_count', 3)
        ->assertJsonPath('stats.rejected_count', 3)
        ->assertJsonPath('stats.proposed_count', 3)
        ->assertJsonPath('stats.verified_count', 1);

    expect($fromDocument->every(fn (ProjectFact $fact): bool => $fact->fresh()->status === ProjectFactStatus::Rejected))->toBeTrue()
        ->and($otherRevision->fresh()->status)->toBe(ProjectFactStatus::Proposed)
        ->and($fromOtherDocument->fresh()->status)->toBe(ProjectFactStatus::Proposed)
        ->and($verified->fresh()->status)->toBe(ProjectFactStatus::Verified)
        ->and($manual->fresh()->status)->toBe(ProjectFactStatus::Proposed)
        ->and(ProjectFact::query()->count())->toBe(7);
});

it('forbids viewers from bulk verifying or rejecting facts', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->fromDocument($document)->proposed()->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-verify'), [
        'ids' => [$fact->id],
    ])->assertForbidden();

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-reject'), [
        'ids' => [$fact->id],
    ])->assertForbidden();

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-reject'), [
        'source_document_id' => $document->id,
        'source_revision' => $document->revision_number,
    ])->assertForbidden();

    expect($fact->fresh()->status)->toBe(ProjectFactStatus::Proposed);
});

it('rolls back bulk verify when any selected fact cannot be authorized', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $proposed = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create();
    $alreadyVerified = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'key' => 'already-verified',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-verify'), [
        'ids' => [$proposed->id, $alreadyVerified->id],
    ])->assertForbidden();

    expect($proposed->fresh()->status)->toBe(ProjectFactStatus::Proposed)
        ->and($alreadyVerified->fresh()->status)->toBe(ProjectFactStatus::Verified);
});

it('rejects bulk actions that include facts from another deployment', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($customer)->create();
    $local = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create();
    $foreign = ProjectFact::factory()->forDeployment($otherDeployment, $fixture['engineer'])->proposed()->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-reject'), [
        'ids' => [$local->id, $foreign->id],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ids']);

    expect($local->fresh()->status)->toBe(ProjectFactStatus::Proposed)
        ->and($foreign->fresh()->status)->toBe(ProjectFactStatus::Proposed);
});

it('supersedes conflicting verified facts during bulk verify', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $existing = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 12',
    ]);
    $proposed = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'framework',
        'key' => 'backend',
        'value' => 'Laravel 13',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-verify'), [
        'ids' => [$proposed->id],
    ])
        ->assertOk()
        ->assertJsonPath('stats.verified_count', 1);

    expect($existing->fresh()->status)->toBe(ProjectFactStatus::Superseded)
        ->and($existing->fresh()->superseded_by_id)->toBe($proposed->id)
        ->and($proposed->fresh()->status)->toBe(ProjectFactStatus::Verified);
});

it('does not mix selected ids with source-scoped bulk reject', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->fromDocument($document)->proposed()->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(projectFactsBulkPath($fixture['workspace'], $customer, $deployment, 'bulk-reject'), [
        'ids' => [$fact->id],
        'source_document_id' => $document->id,
        'source_revision' => $document->revision_number,
    ])->assertUnprocessable();

    expect($fact->fresh()->status)->toBe(ProjectFactStatus::Proposed);
});
