<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('registers a user and returns a sanctum token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Ada Lovelace')
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonStructure([
            'data' => ['id', 'name', 'email'],
            'token',
        ]);

    expect(User::query()->where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('validates registration input', function () {
    $this->postJson('/api/register', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

it('does not register a duplicate email', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('logs in an existing user and returns a token', function () {
    $user = User::factory()->create([
        'email' => 'ada@example.com',
        'password' => 'password',
    ]);

    $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonStructure(['token']);
});

it('rejects invalid login credentials', function () {
    User::factory()->create([
        'email' => 'ada@example.com',
        'password' => 'password',
    ]);

    $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('returns the authenticated user', function () {
    $user = User::factory()->create();
    $token = $user->createToken('auth')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', $user->name)
        ->assertJsonPath('data.email', $user->email);
});

it('requires authentication to view the current user', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});

it('logs out and revokes the current access token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('auth')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out.');

    expect($user->tokens()->count())->toBe(0);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/user')
        ->assertUnauthorized();
});
