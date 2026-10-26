<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\LetterType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $year = now()->year;

        return [
            'id' => (string) Str::uuid(),
            'letter_type_id' => LetterType::factory(),
            'company_id' => null,
            'number' => sprintf(
                '%03d/%s/DIR/X/%d',
                fake()->unique()->numberBetween(1, 999),
                fake()->lexify('???'),
                $year
            ),
            'seq' => fake()->numberBetween(1, 999),
            'unit' => fake()->randomElement(['DIR', 'OPS', 'FIN']),
            'year' => $year,
            'subject' => fake()->sentence(4),
            'date' => fake()->date(),
            'body_html' => null,
            'metadata' => null,
            'classification' => 'umum',
            'status' => 'draft',
            'revoked_at' => null,
            'revocation_reason' => null,
            // Merujuk users di DB sagansa_user — cukup id numerik di test.
            'created_by' => fake()->numberBetween(1, 100000),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function withMetadata(array $metadata): static
    {
        return $this->state(fn () => ['metadata' => $metadata]);
    }
}
