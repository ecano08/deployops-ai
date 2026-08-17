<?php

namespace Database\Factories;

use App\Enums\DeploymentStage;
use App\Models\Customer;
use App\Models\Deployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'stage' => fake()->randomElement(DeploymentStage::cases()),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Deployment $deployment): void {
            if ($deployment->customer_id === null) {
                return;
            }

            $customer = Customer::query()->find($deployment->customer_id);

            if ($customer !== null) {
                $deployment->workspace_id = $customer->workspace_id;
            }
        });
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $customer->workspace_id,
            'customer_id' => $customer->id,
        ]);
    }

    public function stage(DeploymentStage $stage): static
    {
        return $this->state(fn (): array => [
            'stage' => $stage,
        ]);
    }
}
