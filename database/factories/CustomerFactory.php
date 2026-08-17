<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
            'slug' => Customer::uniqueSlugFor($workspace, fake()->company()),
        ]);
    }
}
