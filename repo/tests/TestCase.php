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

    /**
     * Direct PDO connection to the test database.
     * Uses the same env vars injected into the backend container.
     */
    protected function dbPdo(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $name = getenv('DB_NAME') ?: 'campus';
            $user = getenv('DB_USER') ?: 'campus';
            $pass = getenv('DB_PASSWORD') ?: 'campus';
            $pdo  = new \PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return $pdo;
    }

    /**
     * Fetch user_id for a given username directly from the DB.
     */
    protected function dbUserId(string $username): int
    {
        $stmt = $this->dbPdo()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        return (int)$stmt->fetchColumn();
    }
}
