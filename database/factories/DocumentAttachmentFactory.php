<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentAttachment>
 */
class DocumentAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'document_id' => Document::factory(),
            'label' => fake()->words(2, true),
            'sort' => fake()->numberBetween(0, 10),
            'path' => 'documents/'.fake()->uuid.'.pdf',
            'sha256' => hash('sha256', fake()->uuid()),
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 1048576),
        ];
    }
}
