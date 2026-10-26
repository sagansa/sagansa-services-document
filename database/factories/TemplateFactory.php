<?php

namespace Database\Factories;

use App\Models\LetterType;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'letter_type_id' => LetterType::factory(),
            'name' => fake()->words(3, true),
            'body_html' => '<p>'.fake()->sentence().'</p>',
            'placeholders' => ['number', 'date'],
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
