<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DemoSeeding;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        if (DemoSeeding::isEnabled()) {
            $this->call(DemoSeeder::class);
        }
    }
}
