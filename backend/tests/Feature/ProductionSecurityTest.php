<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    public function test_login_and_private_responses_include_defensive_security_headers(): void
    {
        $response = $this->get('/login')->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringNotContainsString("'unsafe-inline'", explode(';', explode('script-src ', $policy)[1], 2)[0]);
        $response->assertDontSee('<script>try{', false);
    }

    public function test_production_http_uses_configured_canonical_https_host(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.url', 'https://exports.example.test');
        $this->withServerVariables(['HTTP_HOST' => 'attacker.example.test', 'HTTPS' => 'off'])
            ->get('/login?continue=1')
            ->assertRedirect('https://exports.example.test/login?continue=1');
    }

    public function test_production_https_requires_secure_session_and_sends_hsts(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.url', 'https://exports.example.test');
        $this->get('https://exports.example.test/login')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_production_refuses_unconfigured_https_origin(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.url', 'http://localhost:8000');
        $this->get('/login')->assertStatus(503);
    }
}
