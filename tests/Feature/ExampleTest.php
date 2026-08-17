<?php

test('the home page presents DeployOps AI branding', function () {
    $response = $this->get('/');

    $response
        ->assertOk()
        ->assertSee('DeployOps AI', false)
        ->assertSee('AI deployment &amp; integration workspace for Forward Deployed Engineers', false)
        ->assertSee('Integrations', false)
        ->assertSee('AI Copilot + Tool Calling', false)
        ->assertSee('RAG Knowledge Base', false)
        ->assertSee('Human Approval', false)
        ->assertSee('Observability', false)
        ->assertDontSee('Let&#039;s get started', false)
        ->assertDontSee('Laravel Documentation', false);
});
