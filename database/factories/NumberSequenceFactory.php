<?php

namespace Database\Factories;

use App\Models\NumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberSequence>
 */
class NumberSequenceFactory extends Factory
{
    public function definition(): array
    {
        // letter_type_code unik agar kombinasi (code, unit, year) unik
        // untuk pembuatan lebih dari satu baris dalam satu test.
        return [
            'letter_type_code' => fake()->unique()->lexify('????'),
            'unit' => fake()->randomElement(['DIR', 'OPS', 'FIN']),
            'year' => now()->year,
            'last_seq' => 0,
        ];
    }

    public function forSequence(string $code, string $unit, int $year): static
    {
        return $this->state(fn () => [
            'letter_type_code' => $code,
            'unit' => $unit,
            'year' => $year,
        ]);
    }
}
