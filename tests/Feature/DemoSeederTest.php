<?php

use App\Models\User;
use App\Support\DemoSeeding;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not seed demo accounts in production', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.env' => 'production', 'demo.seed_demo_data' => true]);

    expect(DemoSeeding::isEnabled())->toBeFalse();

    app(DatabaseSeeder::class)->run();
    app(DemoSeeder::class)->run();

    expect(User::query()->where('email', 'demo@deployops.ai')->exists())->toBeFalse();
});

it('seeds demo accounts in local environment', function () {
    app()->detectEnvironment(fn (): string => 'local');
    config(['app.env' => 'local', 'demo.seed_demo_data' => false]);

    expect(DemoSeeding::isEnabled())->toBeTrue();

    app(DemoSeeder::class)->run();

    expect(User::query()->where('email', 'demo@deployops.ai')->exists())->toBeTrue();
});

it('seeds demo accounts when seed demo data flag is enabled outside production', function () {
    app()->detectEnvironment(fn (): string => 'staging');
    config(['app.env' => 'staging', 'demo.seed_demo_data' => true]);

    expect(DemoSeeding::isEnabled())->toBeTrue();

    app(DemoSeeder::class)->run();

    expect(User::query()->where('email', 'demo@deployops.ai')->exists())->toBeTrue();
});

it('does not seed demo accounts in staging without the seed demo data flag', function () {
    app()->detectEnvironment(fn (): string => 'staging');
    config(['app.env' => 'staging', 'demo.seed_demo_data' => false]);

    expect(DemoSeeding::isEnabled())->toBeFalse();

    app(DatabaseSeeder::class)->run();

    expect(User::query()->where('email', 'demo@deployops.ai')->exists())->toBeFalse();
});
