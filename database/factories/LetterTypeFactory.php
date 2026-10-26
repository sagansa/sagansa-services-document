<?php

namespace Database\Factories;

use App\Models\LetterType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LetterType>
 */
class LetterTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('????'),
            'name' => fake()->words(2, true),
            'specific_fields' => null,
            'is_active' => true,
        ];
    }

    /**
     * Jenis surat dengan definisi field khusus (mis. KUA).
     */
    public function withSpecificFields(array $fields): static
    {
        return $this->state(fn () => ['specific_fields' => $fields]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
