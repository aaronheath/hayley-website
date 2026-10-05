<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebsiteTest extends TestCase
{
    public function test_homepage_renders_with_mix_assets(): void
    {
        $this->get('/')->assertOk()->assertSee("Hayley O'Kelly | CV", false)->assertSee('/css/app.css', false);
    }

    public function test_health_route_is_public_and_does_not_start_a_session(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'missing-connection']);
        $this->get('/up')->assertOk()->assertContent('OK')->assertCookieMissing(config('session.cookie'));
    }

    public function test_leo_renders_a_committed_image(): void
    {
        $this->get('/leo')->assertOk()->assertSee('img/leo/', false);
    }
}
