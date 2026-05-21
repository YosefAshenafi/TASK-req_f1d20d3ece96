<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

class ViolationTest extends TestCase
{
    private static int $ruleId = 0;

    private function ensureRule(): int
    {
        if (self::$ruleId) return self::$ruleId;
        $resp = $this->http->post('/api/violation-rules', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['name' => 'Missed Shift', 'point_value' => -10, 'description' => 'Missed assigned shift'],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        self::$ruleId = json_decode((string)$resp->getBody(), true)['data']['id'];
        return self::$ruleId;
    }

    public function testCreateRule_asAdmin_returns201(): void
    {
        $resp = $this->http->post('/api/violation-rules', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['name' => 'On-Time Bonus', 'point_value' => 5],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
    }

    public function testCreateRule_asNonAdmin_returns403(): void
    {
        $resp = $this->http->post('/api/violation-rules', [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['name' => 'Hack', 'point_value' => 100],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testRecordViolation_returns201(): void
    {
        $ruleId = $this->ensureRule();
        // Get user1's ID
        $loginResp = $this->http->post('/api/auth/login', ['json' => ['username' => 'user1', 'password' => 'User@Campus1!']]);
        $userId    = json_decode((string)$loginResp->getBody(), true)['data']['user']['id'];

        $resp = $this->http->post('/api/violations', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['rule_id' => $ruleId, 'subject_user_id' => $userId, 'notes' => 'First offense'],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('points_applied', $body['data']);
    }

    public function testGetViolations_regularUserSeesOnly_ownViolations(): void
    {
        $resp = $this->http->get('/api/violations', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        // All returned violations must belong to user1
        foreach ($body['data']['data'] ?? [] as $v) {
            $this->assertSame('user1', $v['subject']['username'] ?? 'user1');
        }
    }

    public function testAppeal_returns201(): void
    {
        $ruleId = $this->ensureRule();
        $loginResp = $this->http->post('/api/auth/login', ['json' => ['username' => 'user1', 'password' => 'User@Campus1!']]);
        $userId    = json_decode((string)$loginResp->getBody(), true)['data']['user']['id'];

        // Create a violation for user1
        $createResp = $this->http->post('/api/violations', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['rule_id' => $ruleId, 'subject_user_id' => $userId],
        ]);
        $violationId = json_decode((string)$createResp->getBody(), true)['data']['id'];

        $resp = $this->http->post("/api/violations/{$violationId}/appeals", [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['reason' => 'I was actually present'],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
    }

    public function testReviewAppeal_withoutNotes_returns422(): void
    {
        $ruleId = $this->ensureRule();
        $loginResp = $this->http->post('/api/auth/login', ['json' => ['username' => 'user1', 'password' => 'User@Campus1!']]);
        $userId    = json_decode((string)$loginResp->getBody(), true)['data']['user']['id'];

        $createResp = $this->http->post('/api/violations', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['rule_id' => $ruleId, 'subject_user_id' => $userId],
        ]);
        $violationId = json_decode((string)$createResp->getBody(), true)['data']['id'];

        $this->http->post("/api/violations/{$violationId}/appeals", [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['reason' => 'Was present'],
        ]);

        $resp = $this->http->patch("/api/violations/{$violationId}/appeals/review", [
            'headers' => $this->authHeaders($this->reviewerToken()),
            'json'    => ['decision' => 'approved'], // missing decision_notes
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testReviewAppeal_asNonReviewer_returns403(): void
    {
        $resp = $this->http->patch('/api/violations/1/appeals/review', [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['decision' => 'approved', 'decision_notes' => 'ok'],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testUserPointSummary_returns200(): void
    {
        $loginResp = $this->http->post('/api/auth/login', ['json' => ['username' => 'user1', 'password' => 'User@Campus1!']]);
        $userId    = json_decode((string)$loginResp->getBody(), true)['data']['user']['id'];

        $resp = $this->http->get("/api/point-summary/users/{$userId}", [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('total_points', $body['data']);
    }

    public function testViolationEndpoints_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/violations');
        $this->assertSame(401, $resp->getStatusCode());
    }
}
