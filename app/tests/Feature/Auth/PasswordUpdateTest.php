<?php

namespace Tests\Feature\Auth;

use App\Models\AppUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_password_can_be_updated(): void
    {
        $user = AppUser::factory()->salesEmployee()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/profile');
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password_hash));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = AppUser::factory()->salesEmployee()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response->assertSessionHasErrors();
        $this->assertTrue(Hash::check('password', $user->fresh()->password_hash));
    }
}
