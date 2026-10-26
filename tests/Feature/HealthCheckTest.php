<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Health-check service document harus selalu hidup tanpa dependensi DB.
     */
    public function test_up_returns_ok(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
        $this->assertSame('ok', $response->getContent());
    }
}
