<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Issuance;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Issuance>
 */
class IssuanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'document_id' => Document::factory(),
            'method' => fake()->randomElement(['a', 'b', 'c', 'd']),
            'method_label' => fake()->randomElement(['elektronik', 'ttd_elektronik_resmi', 'manual']),
            'file_path' => 'issued/'.fake()->uuid().'.pdf',
            'file_sha256' => hash('sha256', fake()->uuid()),
            'attachments_sha256' => null,
            'file_size' => fake()->numberBetween(4096, 2097152),
            'pages' => null,
            'note' => null,
            'replaced_by_id' => null,
            'is_active' => true,
            // Merujuk users di DB sagansa_user — cukup id numerik di test.
            'finalized_by' => fake()->numberBetween(1, 100000),
            'finalized_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function replacedBy(string $issuanceId): static
    {
        return $this->state(fn () => ['replaced_by_id' => $issuanceId]);
    }
}
