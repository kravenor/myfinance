<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_forwarded_proto_from_the_docker_network_is_trusted(): void
    {
        $this->assertTrue($this->isSecureBehindProxy('172.18.0.5'));
    }

    public function test_forwarded_proto_from_a_public_ip_is_ignored(): void
    {
        $this->assertFalse($this->isSecureBehindProxy('203.0.113.7'));
    }

    /** Un proxy con `$remoteAddr` dichiara `X-Forwarded-Proto: https`: l'app ci crede? */
    private function isSecureBehindProxy(string $remoteAddr): bool
    {
        $request = Request::create('http://finance.example.com/api/auth/me', server: [
            'REMOTE_ADDR' => $remoteAddr,
        ]);
        $request->headers->set('X-Forwarded-Proto', 'https');

        $secure = null;
        app(TrustProxies::class)->handle($request, function (Request $forwarded) use (&$secure) {
            $secure = $forwarded->isSecure();

            return response()->noContent();
        });

        return (bool) $secure;
    }
}
