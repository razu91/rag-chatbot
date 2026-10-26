<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiExceptionHandlingTest extends TestCase
{
    public function test_api_requests_are_rendered_as_json(): void
    {
        $response = $this->getJson('/api/user');

        $response
            ->assertUnauthorized()
            ->assertJsonStructure([
                'message',
            ]);
    }
}
