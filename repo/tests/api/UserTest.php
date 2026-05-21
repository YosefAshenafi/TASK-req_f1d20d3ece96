<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

/**
 * True no-mock HTTP tests for /api/users endpoints.
 */
class UserTest extends TestCase
{
    // ── List users (Admin only) ──────────────────────────────────────────────

    public function testListUsers_asAdmin_returns200WithList(): void
    {
        $resp = $this->http->get('/api/users', [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);

        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertIsArray($body['data']['data']); // paginated
        // Verify password_hash never exposed
        foreach ($body['data']['data'] as $u) {
            $this->assertArrayNotHasKey('password_hash', $u);
        }
    }

    public function testListUsers_asRegularUser_returns403(): void
    {
        $resp = $this->http->get('/api/users', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);

        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testListUsers_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/users');
        $this->assertSame(401, $resp->getStatusCode());
    }

    // ── Get single user ──────────────────────────────────────────────────────

    public function testGetUser_ownProfile_returns200(): void
    {
        // Login as user1, get their own id
        $token = $this->regularToken();
        $loginResp = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'user1', 'password' => 'User@Campus1!'],
        ]);
        $userId = json_decode((string)$loginResp->getBody(), true)['data']['user']['id'];

        $resp = $this->http->get("/api/users/{$userId}", [
            'headers' => $this->authHeaders($token),
        ]);

        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame($userId, $body['data']['id']);
        $this->assertArrayNotHasKey('password_hash', $body['data']);
    }

    public function testGetUser_anotherUsersProfile_asRegular_returns403(): void
    {
        // Get user2's profile logged in as user1
        $loginResp = $this->http->post('/api/auth/login', [
            'json' => ['username' => 'user2', 'password' => 'User@Campus2!'],
        ]);
        $user2Id = json_decode((string)$loginResp->getBody(), true)['data']['user']['id'];

        $resp = $this->http->get("/api/users/{$user2Id}", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);

        $this->assertSame(403, $resp->getStatusCode());
    }

    // ── Create user ──────────────────────────────────────────────────────────

    public function testCreateUser_asAdmin_returns201(): void
    {
        $unique = 'newuser_' . time();
        $resp = $this->http->post('/api/users', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['username' => $unique, 'password' => 'NewUser@Pass1', 'role' => 'regular'],
        ]);

        $this->assertSame(201, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('id', $body['data']);
        $this->assertArrayNotHasKey('password_hash', $body['data']);
    }

    public function testCreateUser_shortPassword_returns422(): void
    {
        $resp = $this->http->post('/api/users', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['username' => 'shortpassuser', 'password' => 'short', 'role' => 'regular'],
        ]);

        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testCreateUser_duplicateUsername_returns409(): void
    {
        $resp = $this->http->post('/api/users', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['username' => 'admin', 'password' => 'Admin@Campus1', 'role' => 'admin'],
        ]);

        $this->assertSame(409, $resp->getStatusCode());
    }

    public function testCreateUser_asNonAdmin_returns403(): void
    {
        $resp = $this->http->post('/api/users', [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['username' => 'hack', 'password' => 'Hack@Pass123', 'role' => 'admin'],
        ]);

        $this->assertSame(403, $resp->getStatusCode());
    }

    // ── Delete user ──────────────────────────────────────────────────────────

    public function testDeleteUser_asNonAdmin_returns403(): void
    {
        $resp = $this->http->delete('/api/users/1', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);

        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testDeleteNonexistentUser_asAdmin_returns404(): void
    {
        $resp = $this->http->delete('/api/users/999999', [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);

        $this->assertSame(404, $resp->getStatusCode());
    }
}
