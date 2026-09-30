<?php

namespace Tests\Feature\Auth;

use App\Models\AppUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertStatus(200);
    }

    public function test_reset_password_link_request_accepts_app_user_email(): void
    {
        $user = AppUser::factory()->salesEmployee()->create();

        // The custom AppUser provider must resolve the email; invalid emails error.
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user = AppUser::factory()->salesEmployee()->create();

        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password_hash));
    }
}
