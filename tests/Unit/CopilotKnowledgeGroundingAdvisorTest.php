<?php

use App\Services\CopilotKnowledgeGroundingAdvisor;

it('detects short contextual follow-up questions', function () {
    $advisor = new CopilotKnowledgeGroundingAdvisor;

    expect($advisor->isContextualFollowUp('And who has priority?'))->toBeTrue()
        ->and($advisor->isContextualFollowUp('¿Y quién tiene prioridad?'))->toBeTrue()
        ->and($advisor->isContextualFollowUp('What stage is this deployment in?'))->toBeFalse();
});

it('requires proactive knowledge search when follow-up history exists and search was skipped', function () {
    $advisor = new CopilotKnowledgeGroundingAdvisor;

    $history = [[
        'question' => 'What happens if the customer does not pay within 15 minutes?',
        'answer' => 'The reservation expires after 15 minutes.',
    ]];

    expect($advisor->shouldForceKnowledgeSearch($history, 'And who has priority?', []))->toBeTrue()
        ->and($advisor->shouldForceKnowledgeSearch($history, 'And who has priority?', ['search_knowledge']))->toBeFalse()
        ->and($advisor->shouldForceKnowledgeSearch([], 'And who has priority?', []))->toBeFalse();
});

it('builds follow-up search queries from the current and prior user questions', function () {
    $advisor = new CopilotKnowledgeGroundingAdvisor;

    $history = [[
        'question' => 'What happens if the customer does not pay within 15 minutes?',
        'answer' => 'The reservation expires after 15 minutes.',
    ]];

    expect($advisor->buildSearchQuery('And who has priority?', $history))
        ->toContain('And who has priority?')
        ->toContain('15 minutes');
});
