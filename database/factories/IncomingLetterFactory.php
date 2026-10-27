<?php

namespace Database\Factories;

use App\Models\IncomingLetter;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncomingLetter>
 */
class IncomingLetterFactory extends Factory
{
    public function definition(): array
    {
        $year = now()->year;
        $seq = fake()->unique()->numberBetween(1, 99999);

        return [
            'id' => (string) Str::uuid(),
            'company_id' => null,
            // Format agenda "SM-001/X/2026" (counter reuse number_sequences).
            'agenda_number' => sprintf('SM-%05d/X/%d', $seq, $year),
            'agenda_seq' => $seq,
            'year' => $year,
            'number_external' => fake()->optional()->numerify('###/EXT/####'),
            'letter_date' => fake()->optional()->date(),
            'received_at' => fake()->date(),
            'sender_party_id' => Party::factory(),
            'subject' => fake()->sentence(4),
            'sifat' => 'biasa',
            'classification' => 'umum',
            'status' => 'terdaftar',
            // Scan bukti tunggal (immutable, diatur service — bukan fillable).
            'scan_path' => 'incoming-scans/'.Str::uuid().'.pdf',
            'scan_sha256' => fake()->sha256(),
            'scan_size' => fake()->numberBetween(10_000, 500_000),
            'scan_pages' => fake()->optional()->numberBetween(1, 20),
            'attachments_note' => null,
            // Merujuk users di DB sagansa_user — cukup id numerik di test.
            'created_by' => fake()->numberBetween(1, 100000),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function sifat(string $sifat): static
    {
        $classification = $sifat === 'rahasia' ? 'rahasia' : 'umum';

        return $this->state(fn () => [
            'sifat' => $sifat,
            'classification' => $classification,
        ]);
    }
}
