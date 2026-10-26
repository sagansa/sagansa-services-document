<?php

namespace Database\Factories;

use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'identity_no' => fake()->optional()->numerify('################'),
            'address' => fake()->optional()->address(),
            'occupation' => fake()->optional()->jobTitle(),
            'notes' => null,
            'created_from_document_id' => null,
        ];
    }
}
