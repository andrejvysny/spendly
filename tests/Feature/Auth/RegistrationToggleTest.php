<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_reachable_when_enabled(): void
    {
        config(['auth.registration_enabled' => true]);

        $this->get('/register')->assertOk();
    }

    public function test_registration_screen_404s_when_disabled(): void
    {
        config(['auth.registration_enabled' => false]);

        $this->get('/register')->assertNotFound();
    }

    public function test_registration_cannot_be_posted_when_disabled(): void
    {
        config(['auth.registration_enabled' => false]);

        $this->post('/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
    }

    public function test_register_route_stays_registered_so_route_helper_resolves(): void
    {
        config(['auth.registration_enabled' => false]);

        // Ziggy exposes named routes to the frontend; login.tsx calls route('register')
        // unconditionally, so the name must keep resolving even when sign-up is closed.
        $this->assertSame(url('/register'), route('register'));
    }
}
