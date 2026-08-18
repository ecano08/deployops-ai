<?php

use App\Enums\GroundedContextKind;
use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\ProjectFactStatus;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\ProjectFact;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

function groundedContextPath(Workspace $workspace, Customer $customer, Deployment $deployment): string
{
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/grounded-context';
}

/**
 * @param  list<array<string, mixed>>  $results
 */
function fakeKnowledgeSearch(array $results = []): void
{
    Http::preventStrayRequests();

    Http::fake([
        'http://ai-service.test/search' => Http::response([
            'results' => $results,
        ]),
    ]);
}

beforeEach(function () {
    config([
        'services.ai_service.url' => 'http://ai-service.test',
        'services.ai_service.token' => 'test-ai-service-token',
    ]);
});

it('requires authentication to build grounded context', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $this->postJson(groundedContextPath($workspace, $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])->assertUnauthorized();
});

it('forbids strangers from building grounded context', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['stranger']);

    $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])->assertForbidden();
});

it('allows workspace viewers to build grounded context', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    fakeKnowledgeSearch();

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])
        ->assertOk()
        ->assertJsonPath('data.query', 'How should cart reservation expiration work?')
        ->assertJsonStructure([
            'data' => [
                'facts',
                'documents',
                'conflicts',
                'unknowns',
                'sources',
            ],
        ]);
});

it('includes only verified facts in grounded context', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $verified = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '15 minutes',
        'source_reference' => 'Cart reservations expire after 15 minutes.',
    ]);
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->proposed()->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '10 minutes',
        'source_reference' => 'Cart reservations expire after 10 minutes.',
    ]);
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->rejected($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '20 minutes',
        'source_reference' => 'Cart reservations expire after 20 minutes.',
    ]);
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->superseded($verified)->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '5 minutes',
        'source_reference' => 'Cart reservations expire after 5 minutes.',
    ]);

    fakeKnowledgeSearch();

    Sanctum::actingAs($fixture['viewer']);

    $response = $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])->assertOk();

    expect($response->json('data.facts'))->toHaveCount(1)
        ->and($response->json('data.facts.0.id'))->toBe($verified->id)
        ->and($response->json('data.facts.0.grounding'))->toBe(GroundedContextKind::VerifiedFact->value)
        ->and($response->json('data.facts.0.provenance.status'))->toBe(ProjectFactStatus::Verified->value);
});

it('includes only active ready document chunks', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $activeReady = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Reservation policy',
        'original_filename' => 'reservation-policy.pdf',
    ]);
    $draft = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->draft()->create([
        'original_filename' => 'draft-policy.pdf',
    ]);
    $superseded = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->create([
        'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Superseded,
        'original_filename' => 'old-policy.pdf',
    ]);
    $archived = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->create([
        'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Archived,
        'original_filename' => 'archived-policy.pdf',
    ]);

    fakeKnowledgeSearch([
        [
            'document_id' => $activeReady->id,
            'source_filename' => 'reservation-policy.pdf',
            'chunk_index' => 0,
            'content' => 'Cart reservations expire after 15 minutes if unpaid.',
            'score' => 0.94,
        ],
        [
            'document_id' => $draft->id,
            'source_filename' => 'draft-policy.pdf',
            'chunk_index' => 0,
            'content' => 'Draft: cart reservations expire after 45 minutes.',
            'score' => 0.99,
        ],
        [
            'document_id' => $superseded->id,
            'source_filename' => 'old-policy.pdf',
            'chunk_index' => 0,
            'content' => 'Superseded: cart reservations expire after 5 minutes.',
            'score' => 0.98,
        ],
        [
            'document_id' => $archived->id,
            'source_filename' => 'archived-policy.pdf',
            'chunk_index' => 0,
            'content' => 'Archived: cart reservations expire after 60 minutes.',
            'score' => 0.97,
        ],
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $response = $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])->assertOk();

    expect($response->json('data.documents'))->toHaveCount(1)
        ->and($response->json('data.documents.0.document_id'))->toBe($activeReady->id)
        ->and($response->json('data.documents.0.grounding'))->toBe(GroundedContextKind::Documented->value)
        ->and($response->json('data.documents.0.provenance.lifecycle_status'))->toBe(KnowledgeDocumentLifecycleStatus::Active->value)
        ->and($response->json('data.documents.0.provenance.status'))->toBe(KnowledgeDocumentStatus::Ready->value);

    Http::assertSent(function (Request $request) use ($activeReady, $draft, $superseded, $archived) {
        $body = $request->data();

        return $request->url() === 'http://ai-service.test/search'
            && $body['document_ids'] === [$activeReady->id]
            && ! in_array($draft->id, $body['document_ids'], true)
            && ! in_array($superseded->id, $body['document_ids'], true)
            && ! in_array($archived->id, $body['document_ids'], true);
    });
});

it('filters facts and documents by relevance to the query', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $relevantDocument = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'original_filename' => 'reservation-policy.pdf',
    ]);
    $unrelatedDocument = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'original_filename' => 'payments.pdf',
    ]);

    $relevantFact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '15 minutes',
        'source_reference' => 'Cart reservations expire after 15 minutes.',
    ]);
    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'payments',
        'key' => 'gateway',
        'value' => 'Stripe',
        'source_reference' => 'Card charges go through Stripe.',
    ]);
    $inferredFact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'checkout_flow',
        'value' => 'multi-step checkout',
        'source_reference' => 'Checkout is multi-step.',
    ]);

    fakeKnowledgeSearch([
        [
            'document_id' => $relevantDocument->id,
            'source_filename' => 'reservation-policy.pdf',
            'chunk_index' => 0,
            'content' => 'Cart reservations expire after 15 minutes if unpaid.',
            'score' => 0.94,
        ],
        [
            'document_id' => $unrelatedDocument->id,
            'source_filename' => 'payments.pdf',
            'chunk_index' => 0,
            'content' => 'Stripe is the payment gateway for card charges.',
            'score' => 0.12,
        ],
        [
            'document_id' => $relevantDocument->id,
            'source_filename' => 'reservation-policy.pdf',
            'chunk_index' => 1,
            'content' => 'Cart checkout is multi-step after reservation.',
            'score' => 0.41,
        ],
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $response = $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])->assertOk();

    $factIds = collect($response->json('data.facts'))->pluck('id')->all();
    $documentEntries = collect($response->json('data.documents'));

    expect($factIds)->toContain($relevantFact->id)
        ->and($factIds)->toContain($inferredFact->id)
        ->and($factIds)->not->toContain(
            ProjectFact::query()->where('key', 'gateway')->value('id'),
        )
        ->and($response->json('data.facts.0.id'))->toBe($relevantFact->id)
        ->and($response->json('data.facts.0.grounding'))->toBe(GroundedContextKind::VerifiedFact->value)
        ->and(collect($response->json('data.facts'))->firstWhere('id', $inferredFact->id)['grounding'])
        ->toBe(GroundedContextKind::Inferred->value)
        ->and($documentEntries->pluck('score')->all())->not->toContain(0.12)
        ->and($documentEntries->firstWhere('score', 0.94)['grounding'])->toBe(GroundedContextKind::Documented->value)
        ->and($documentEntries->firstWhere('score', 0.41)['grounding'])->toBe(GroundedContextKind::Inferred->value);
});

it('keeps provenance for facts and documents', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'title' => 'Reservation policy',
        'original_filename' => 'reservation-policy.pdf',
        'revision_number' => 3,
    ]);
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->fromDocument($document)->verified($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '15 minutes',
        'source_reference' => 'Section 2.1: expire after 15 minutes.',
        'source_revision' => 3,
    ]);

    fakeKnowledgeSearch([
        [
            'document_id' => $document->id,
            'source_filename' => 'reservation-policy.pdf',
            'chunk_index' => 2,
            'content' => 'Cart reservations expire after 15 minutes if unpaid.',
            'score' => 0.91,
        ],
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])
        ->assertOk()
        ->assertJsonPath('data.facts.0.provenance.type', 'project_fact')
        ->assertJsonPath('data.facts.0.provenance.fact_id', $fact->id)
        ->assertJsonPath('data.facts.0.provenance.source_document_id', $document->id)
        ->assertJsonPath('data.facts.0.provenance.source_revision', 3)
        ->assertJsonPath('data.facts.0.provenance.source_reference', 'Section 2.1: expire after 15 minutes.')
        ->assertJsonPath('data.documents.0.provenance.type', 'knowledge_document')
        ->assertJsonPath('data.documents.0.provenance.document_id', $document->id)
        ->assertJsonPath('data.documents.0.provenance.chunk_index', 2)
        ->assertJsonPath('data.documents.0.provenance.revision_number', 3)
        ->assertJsonPath('data.sources.0.type', 'project_fact')
        ->assertJsonPath('data.sources.0.id', $fact->id)
        ->assertJsonPath('data.sources.1.type', 'knowledge_document')
        ->assertJsonPath('data.sources.1.id', $document->id);
});

it('detects conflicts between verified facts and document evidence', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'original_filename' => 'reservation-policy.pdf',
    ]);
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '15 minutes',
        'source_reference' => 'Cart reservations expire after 15 minutes.',
    ]);

    fakeKnowledgeSearch([
        [
            'document_id' => $document->id,
            'source_filename' => 'reservation-policy.pdf',
            'chunk_index' => 0,
            'content' => 'Cart reservations expire after 30 minutes if unpaid.',
            'score' => 0.93,
        ],
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $response = $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])->assertOk();

    expect($response->json('data.conflicts'))->toHaveCount(1)
        ->and($response->json('data.conflicts.0.grounding'))->toBe(GroundedContextKind::Conflicting->value)
        ->and($response->json('data.conflicts.0.topic'))->toBe('cart.reservation_expiration')
        ->and($response->json('data.conflicts.0.fact_ids'))->toBe([$fact->id])
        ->and($response->json('data.conflicts.0.document_ids'))->toBe([$document->id])
        ->and($response->json('data.conflicts.0.summary'))->toContain('15 minutes')
        ->and($response->json('data.conflicts.0.summary'))->toContain('30 minutes');
});

it('detects unknowns when no verified facts or documents match', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'original_filename' => 'reservation-policy.pdf',
    ]);

    ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '15 minutes',
    ]);

    fakeKnowledgeSearch([
        [
            'document_id' => $document->id,
            'source_filename' => 'reservation-policy.pdf',
            'chunk_index' => 0,
            'content' => 'Cart reservations expire after 15 minutes if unpaid.',
            'score' => 0.94,
        ],
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $response = $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How does kubernetes autoscaling work?',
    ])->assertOk();

    expect($response->json('data.facts'))->toBe([])
        ->and($response->json('data.documents'))->toBe([])
        ->and($response->json('data.unknowns'))->not->toBeEmpty()
        ->and($response->json('data.unknowns.0.grounding'))->toBe(GroundedContextKind::Unknown->value)
        ->and($response->json('data.unknowns.0.reason'))->toContain('No verified facts or active, ready documentation');
});

it('isolates grounded context by workspace customer and deployment', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherCustomer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $otherDeployment = Deployment::factory()->forCustomer($otherCustomer)->create();
    $document = KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create([
        'original_filename' => 'reservation-policy.pdf',
    ]);
    $foreignDocument = KnowledgeDocument::factory()->forDeployment($otherDeployment, $fixture['engineer'])->ready()->active()->create([
        'original_filename' => 'foreign-policy.pdf',
    ]);

    $localFact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '15 minutes',
        'source_reference' => 'Cart reservations expire after 15 minutes.',
    ]);
    ProjectFact::factory()->forDeployment($otherDeployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '90 minutes',
        'source_reference' => 'Foreign cart reservations expire after 90 minutes.',
    ]);

    fakeKnowledgeSearch([
        [
            'document_id' => $document->id,
            'source_filename' => 'reservation-policy.pdf',
            'chunk_index' => 0,
            'content' => 'Cart reservations expire after 15 minutes if unpaid.',
            'score' => 0.94,
        ],
        [
            'document_id' => $foreignDocument->id,
            'source_filename' => 'foreign-policy.pdf',
            'chunk_index' => 0,
            'content' => 'Foreign cart reservations expire after 90 minutes.',
            'score' => 0.99,
        ],
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $response = $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])->assertOk();

    expect(collect($response->json('data.facts'))->pluck('id')->all())->toBe([$localFact->id])
        ->and(collect($response->json('data.documents'))->pluck('document_id')->all())->toBe([$document->id])
        ->and(collect($response->json('data.sources'))->pluck('id')->all())->toContain($localFact->id)
        ->and(collect($response->json('data.sources'))->pluck('id')->all())->toContain($document->id)
        ->and(collect($response->json('data.sources'))->pluck('id')->all())->not->toContain($foreignDocument->id);

    Http::assertSent(function (Request $request) use ($fixture, $customer, $deployment, $document, $foreignDocument) {
        $body = $request->data();

        return $request->url() === 'http://ai-service.test/search'
            && $body['workspace_id'] === $fixture['workspace']->id
            && $body['customer_id'] === $customer->id
            && $body['deployment_id'] === $deployment->id
            && $body['document_ids'] === [$document->id]
            && ! in_array($foreignDocument->id, $body['document_ids'], true);
    });
});

it('still returns verified facts when knowledge search is unavailable', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    KnowledgeDocument::factory()->forDeployment($deployment, $fixture['engineer'])->ready()->active()->create();
    $fact = ProjectFact::factory()->forDeployment($deployment, $fixture['engineer'])->verified($fixture['admin'])->create([
        'category' => 'cart',
        'key' => 'reservation_expiration',
        'value' => '15 minutes',
        'source_reference' => 'Cart reservations expire after 15 minutes.',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'http://ai-service.test/search' => Http::failedConnection(),
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(groundedContextPath($fixture['workspace'], $customer, $deployment), [
        'query' => 'How should cart reservation expiration work?',
    ])
        ->assertOk()
        ->assertJsonPath('data.facts.0.id', $fact->id)
        ->assertJsonPath('data.documents', []);
});
