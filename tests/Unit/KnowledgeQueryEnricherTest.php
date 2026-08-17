<?php

use App\Services\KnowledgeQueryEnricher;

it('enriches ambiguous follow-up queries with prior user question terms but not assistant answers', function () {
    $enricher = new KnowledgeQueryEnricher;

    $enriched = $enricher->enrich(
        searchQuery: 'priority same product reservation',
        currentQuestion: 'And who has priority if two people want the same product?',
        userQuestionHistory: [
            'What happens if the user does not pay within the 15-minute reservation?',
        ],
    );

    expect($enriched['query'])->toContain('priority')
        ->and($enriched['query'])->toContain('15')
        ->and($enriched['query'])->toContain('reservation')
        ->and($enriched['lexical_terms'])->toContain('15')
        ->and($enriched['lexical_terms'])->toContain('reservation');
});

it('preserves exact numbers and units from the user question in lexical terms', function () {
    $enricher = new KnowledgeQueryEnricher;

    $enriched = $enricher->enrich(
        searchQuery: '¿Qué pasa si no paga dentro de los 15 minutos?',
        currentQuestion: '¿Qué pasa si no paga dentro de los 15 minutos?',
    );

    expect($enriched['lexical_terms'])->toContain('15')
        ->and($enriched['lexical_terms'])->toContain('15 minutos')
        ->and($enriched['lexical_terms'])->toContain('minutos');
});

it('does not enrich when there is no conversational context', function () {
    $enricher = new KnowledgeQueryEnricher;

    $enriched = $enricher->enrich(
        searchQuery: 'rollback deployment steps',
    );

    expect($enriched['query'])->toBe('rollback deployment steps')
        ->and($enriched['lexical_terms'])->toContain('rollback')
        ->and($enriched['lexical_terms'])->toContain('deployment')
        ->and($enriched['lexical_terms'])->toContain('steps');
});
