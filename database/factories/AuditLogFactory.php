<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'actor_user_id' => fake()->numberBetween(1, 100000),
            'event' => fake()->lexify('??????'),
            'payload' => null,
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function event(string $event, array $payload = []): static
    {
        return $this->state(fn () => [
            'event' => $event,
            'payload' => $payload,
        ]);
    }
}
