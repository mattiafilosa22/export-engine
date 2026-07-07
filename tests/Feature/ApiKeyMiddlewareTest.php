<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_pass_when_no_key_is_configured(): void
    {
        config(['gamindo.api_key' => null]);

        $this->postJson('/api/v1/versions', ['name' => 'Open'])->assertStatus(201);
    }

    public function test_it_rejects_a_request_without_the_header_when_a_key_is_set(): void
    {
        config(['gamindo.api_key' => 'secret']);

        $this->postJson('/api/v1/versions', ['name' => 'Guarded'])->assertStatus(401);
    }

    public function test_it_accepts_a_request_with_the_correct_header(): void
    {
        config(['gamindo.api_key' => 'secret']);

        $this->postJson('/api/v1/versions', ['name' => 'Guarded'], ['X-Api-Key' => 'secret'])
            ->assertStatus(201);
    }

    public function test_it_rejects_a_wrong_key(): void
    {
        config(['gamindo.api_key' => 'secret']);

        $this->postJson('/api/v1/versions', ['name' => 'Guarded'], ['X-Api-Key' => 'nope'])
            ->assertStatus(401);
    }
}
