<?php

namespace Database\Factories;

use App\Models\Signer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signer>
 */
class SignerFactory extends Factory
{
    public function definition(): array
    {
        return [
            // user_id + role unik (constraint unique(user_id, role)).
            'user_id' => fake()->unique()->numberBetween(1, 1000000),
            'role' => fake()->randomElement(['direktur', 'komisaris', 'pemegang_saham']),
            'display_name' => fake()->name(),
            'title' => fake()->optional()->jobTitle(),
            'signature_asset_path' => null,
            'is_active' => true,
        ];
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
