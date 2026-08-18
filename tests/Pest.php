<?php

use App\Enums\WorkspaceRole;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * @return array{
 *     workspace: Workspace,
 *     owner: User,
 *     admin: User,
 *     engineer: User,
 *     viewer: User,
 *     stranger: User
 * }
 */
function createWorkspaceWithRoles(): array
{
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $engineer = User::factory()->create();
    $viewer = User::factory()->create();
    $stranger = User::factory()->create();

    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $workspace->members()->attach($engineer, ['role' => WorkspaceRole::Engineer->value]);
    $workspace->members()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

    return compact('workspace', 'owner', 'admin', 'engineer', 'viewer', 'stranger');
}

function copilotPath(Workspace $workspace, Customer $customer, Deployment $deployment): string
{
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/copilot';
}

function evaluationDatasetsPath(Workspace $workspace, Customer $customer, Deployment $deployment): string
{
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/evaluation-datasets';
}

function evaluationRunsPath(Workspace $workspace, Customer $customer, Deployment $deployment): string
{
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/evaluation-runs';
}

function aiActionsPath(Workspace $workspace, Customer $customer, Deployment $deployment): string
{
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/ai-actions';
}

function aiHealthPath(Workspace $workspace, Customer $customer, Deployment $deployment): string
{
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/ai-health';
}

function aiTracesPath(Workspace $workspace, Customer $customer, Deployment $deployment): string
{
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/ai-traces';
}

function incidentsPath(Workspace $workspace, Customer $customer, Deployment $deployment): string
{
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/incidents';
}

function openAiIncompleteToolCallResponse(
    string $name,
    string $arguments = '{',
    string $reason = 'max_output_tokens',
    string $responseId = 'resp_incomplete',
    string $callId = 'call_123',
): array {
    return [
        'id' => $responseId,
        'status' => 'incomplete',
        'incomplete_details' => [
            'reason' => $reason,
        ],
        'output' => [
            [
                'type' => 'function_call',
                'call_id' => $callId,
                'name' => $name,
                'arguments' => $arguments,
            ],
        ],
    ];
}

function openAiToolCallResponse(string $name, string $arguments = '{}', string $responseId = 'resp_tool', string $callId = 'call_123'): array
{
    return [
        'id' => $responseId,
        'status' => 'completed',
        'output' => [
            [
                'type' => 'function_call',
                'call_id' => $callId,
                'name' => $name,
                'arguments' => $arguments,
            ],
        ],
    ];
}

/**
 * @param  array<int, array{call_id: string, name: string, arguments?: string}>  $calls
 */
function openAiMultipleToolCallResponse(array $calls, string $responseId = 'resp_tool'): array
{
    return [
        'id' => $responseId,
        'status' => 'completed',
        'output' => array_map(
            fn (array $call): array => [
                'type' => 'function_call',
                'call_id' => $call['call_id'],
                'name' => $call['name'],
                'arguments' => $call['arguments'] ?? '{}',
            ],
            $calls,
        ),
    ];
}

function openAiMessageResponse(string $text, string $responseId = 'resp_final'): array
{
    return [
        'id' => $responseId,
        'status' => 'completed',
        'output_text' => $text,
        'output' => [
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => $text,
                    ],
                ],
            ],
        ],
    ];
}
