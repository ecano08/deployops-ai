<?php

use App\Enums\WorkspaceRole;
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
