<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\VerificationHit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerificationHit>
 */
class VerificationHitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'ip' => fake()->ipv4(),
        ];
    }
}
