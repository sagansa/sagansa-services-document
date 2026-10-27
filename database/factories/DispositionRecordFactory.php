<?php

namespace Database\Factories;

use App\Models\DispositionRecord;
use App\Models\IncomingLetter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DispositionRecord>
 */
class DispositionRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'incoming_letter_id' => IncomingLetter::factory(),
            // Penerima disposisi: users di DB sagansa_user (signer aktif).
            'to_user_id' => fake()->numberBetween(1, 100000),
            'instruction' => fake()->sentence(6),
            'status' => 'open',
            'acted_at' => null,
            'created_by' => fake()->numberBetween(1, 100000),
        ];
    }

    /**
     * Disposisi selesai: status `selesai` + acted_at terisi.
     */
    public function selesai(): static
    {
        return $this->state(fn () => [
            'status' => 'selesai',
            'acted_at' => now(),
        ]);
    }
}
