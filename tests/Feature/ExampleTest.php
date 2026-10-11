<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->withoutVite()->get('/');

        $response->assertOk()
            ->assertSee('شركتك أوضح. فريقك أقرب.')
            ->assertSee('id="teami-root"', false)
            ->assertSee('lang="ar" dir="rtl"', false)
            ->assertSee('name="csrf-token"', false);
    }
}
