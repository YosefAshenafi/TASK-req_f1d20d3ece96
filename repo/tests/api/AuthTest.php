<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

/**
 * True no-mock HTTP tests for POST /api/auth/login and POST /api/auth/logout.
 * All requests hit the real running ThinkPHP server via Guzzle.
 */
class AuthTest extends TestCase
{
    // ── Login happy path ────────────────────────────────────────────────────

    public function testLogin_validCredentials_returns200WithToken(): void
    {
        $resp = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'admin', 'password' => 'Admin@Campus1'],
        ]);

        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame(200, $body['code']);
        $this->assertArrayHasKey('token', $body['data']);
        $this->assertArrayHasKey('user',  $body['data']);
        $this->assertSame('admin', $body['data']['user']['role']);
        // password_hash must NOT be in response
        $this->assertArrayNotHasKey('password_hash', $body['data']['user']);
    }

    // ── Wrong password ──────────────────────────────────────────────────────

    public function testLogin_wrongPassword_returns401(): void
    {
        $resp = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'ops_user', 'password' => 'WrongPassword99'],
        ]);

        $this->assertSame(401, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame(401, $body['code']);
    }

    // ── Unknown user ────────────────────────────────────────────────────────

    public function testLogin_unknownUser_returns401(): void
    {
        $resp = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'nobody_exists', 'password' => 'SomePassword1'],
        ]);

        $this->assertSame(401, $resp->getStatusCode());
    }

    // ── Validation: password too short ──────────────────────────────────────

    public function testLogin_shortPassword_returns422(): void
    {
        $resp = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'admin', 'password' => 'short'],
        ]);

        $this->assertSame(422, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame(422, $body['code']);
        $this->assertArrayHasKey('errors', $body);
    }

    // ── Validation: missing username ─────────────────────────────────────────

    public function testLogin_missingUsername_returns422(): void
    {
        $resp = $this->http->post('/api/auth/login', [
            'json' => ['password' => 'Admin@Campus1'],
        ]);

        $this->assertSame(422, $resp->getStatusCode());
    }

    // ── Logout ──────────────────────────────────────────────────────────────

    public function testLogout_authenticated_returns200(): void
    {
        $token = $this->adminToken();
        $resp  = $this->http->post('/api/auth/logout', [
            'headers' => $this->authHeaders($token),
        ]);

        $this->assertSame(200, $resp->getStatusCode());
    }

    // ── Logout without token ────────────────────────────────────────────────

    public function testLogout_noToken_returns401(): void
    {
        $resp = $this->http->post('/api/auth/logout');
        $this->assertSame(401, $resp->getStatusCode());
    }

    // ── Account lockout ─────────────────────────────────────────────────────

    public function testLogin_lockoutAfter5Failures_returns401WithLockMessage(): void
    {
        // Use a unique throwaway account so we don't poison other test users
        // We need to create this user via the admin API first
        $adminToken = $this->adminToken();
        $lockUser   = 'locktest_' . time();
        $lockPass   = 'LockTest@123';

        $createResp = $this->http->post('/api/users', [
            'headers' => $this->authHeaders($adminToken),
            'json'    => ['username' => $lockUser, 'password' => $lockPass, 'role' => 'regular'],
        ]);
        $this->assertSame(201, $createResp->getStatusCode());

        // 5 wrong attempts
        for ($i = 0; $i < 5; $i++) {
            $this->http->post('/api/auth/login', [
                'json' => ['username' => $lockUser, 'password' => 'WrongPassword123'],
            ]);
        }

        // 6th attempt — should get locked response
        $resp = $this->http->post('/api/auth/login', [
            'json' => ['username' => $lockUser, 'password' => $lockPass],
        ]);
        $this->assertSame(401, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertStringContainsString('locked', strtolower($body['msg']));
    }
}
