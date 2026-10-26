<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Signer;
use App\Models\SigningRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SigningRequest>
 */
class SigningRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'document_id' => Document::factory(),
            'signer_id' => Signer::factory(),
            'status' => 'diminta',
            'requested_at' => now(),
            'acted_at' => null,
            'note' => null,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
