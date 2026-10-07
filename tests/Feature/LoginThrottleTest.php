<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    public function test_password_guessing_is_slowed_down(): void
    {
        RateLimiter::clear('login:guess@example.com|127.0.0.1');
        for ($i = 0; $i < 5; $i++) {
            Livewire::test('auth.login')->set('email', 'guess@example.com')->set('password', 'wrong'.$i)->call('login')->assertHasErrors('email');
        }
        $t = Livewire::test('auth.login')->set('email', 'guess@example.com')->set('password', 'wrong-again')->call('login');
        $t->assertHasErrors('email');
        $this->assertStringContainsString('Too many', collect($t->errors()->get('email'))->first());
        RateLimiter::clear('login:guess@example.com|127.0.0.1');
    }
}
