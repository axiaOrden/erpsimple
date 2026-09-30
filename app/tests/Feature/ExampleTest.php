<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_application_redirects_guests_to_login(): void
    {
        $this->get('/')
            ->assertRedirect(route('dashboard', absolute: false));
    }
}
