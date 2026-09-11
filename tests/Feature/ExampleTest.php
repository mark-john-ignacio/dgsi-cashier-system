<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example. Post-cutover, `/` is a redirect into the
     * Filament panel (see routes/web.php).
     */
    public function test_the_application_redirects_to_the_filament_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/app');
    }
}
