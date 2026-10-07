<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_home_address_sends_visitors_to_sign_in(): void
    {
        // The home address sends visitors to sign in.
        $this->get('/')->assertRedirect('/login');
    }
}
