<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    /**
     * Endpoint API dilindungi Sanctum — tanpa Bearer token harus 401.
     */
    public function test_user_endpoint_requires_token(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
    }
}
