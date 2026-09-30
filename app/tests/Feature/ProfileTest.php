<?php

namespace Tests\Feature;

use App\Models\AppUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_profile_page_is_displayed(): void
    {
        $user = AppUser::factory()->salesEmployee()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
    }

    public function test_display_name_can_be_updated(): void
    {
        $user = AppUser::factory()->salesEmployee()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Updated Name',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/profile');
        $this->assertSame('Updated Name', $user->fresh()->name);
    }

    public function test_email_cannot_be_changed_by_the_user(): void
    {
        $user = AppUser::factory()->salesEmployee()->create(['email' => 'fixed@erp.test']);

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Updated Name',
                'email' => 'attacker@erp.test',
            ]);

        $this->assertSame('fixed@erp.test', $user->fresh()->email);
    }

    public function test_account_self_deletion_is_disabled(): void
    {
        $user = AppUser::factory()->salesEmployee()->create();

        $response = $this->actingAs($user)->delete('/profile');

        $response->assertNotFound();
        $this->assertModelExists($user);
    }
}
