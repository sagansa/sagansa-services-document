<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\IncomingLetter;
use App\Models\LetterLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LetterLink>
 */
class LetterLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'parent_type' => 'document',
            'parent_id' => Document::factory(),
            'child_type' => 'incoming_letter',
            'child_id' => IncomingLetter::factory(),
            'relation' => 'balasan',
            'created_by' => fake()->numberBetween(1, 100000),
        ];
    }

    public function relation(string $relation): static
    {
        return $this->state(fn () => ['relation' => $relation]);
    }

    /**
     * Edge antar dua surat keluar (document ↔ document).
     */
    public function betweenDocuments(): static
    {
        return $this->state(fn () => [
            'parent_type' => 'document',
            'parent_id' => Document::factory(),
            'child_type' => 'document',
            'child_id' => Document::factory(),
        ]);
    }
}
