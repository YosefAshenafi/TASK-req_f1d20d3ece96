<?php
declare(strict_types=1);
namespace tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use GuzzleHttp\Client;

abstract class TestCase extends PhpUnitTestCase
{
    protected Client $http;
    protected static array $tokens = []; // cached tokens per username

    protected function setUp(): void
    {
        $this->http = new Client([
            'base_uri'        => BASE_URL,
            'http_errors'     => false,
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Login and cache the JWT token for a given user.
     */
    protected function loginAs(string $username, string $password): string
    {
        $key = $username . ':' . $password;
        if (isset(self::$tokens[$key])) {
            return self::$tokens[$key];
        }

        $resp = $this->http->post('/api/auth/login', [
            'json' => ['username' => $username, 'password' => $password],
        ]);

        $this->assertSame(200, $resp->getStatusCode(), "Login failed for {$username}");
        $body  = json_decode((string)$resp->getBody(), true);
        $token = $body['data']['token'];
        self::$tokens[$key] = $token;
        return $token;
    }

    protected function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    protected function adminToken(): string
    {
        return $this->loginAs('admin', 'Admin@Campus1');
    }

    protected function opsToken(): string
    {
        return $this->loginAs('ops_user', 'Ops@Campus1');
    }

    protected function regularToken(): string
    {
        return $this->loginAs('user1', 'User@Campus1!');
    }

    protected function regular2Token(): string
    {
        return $this->loginAs('user2', 'User@Campus2!');
    }

    protected function teamLeadToken(): string
    {
        return $this->loginAs('team_lead', 'Lead@Campus1');
    }

    protected function reviewerToken(): string
    {
        return $this->loginAs('reviewer', 'Review@Campus1');
    }
}
