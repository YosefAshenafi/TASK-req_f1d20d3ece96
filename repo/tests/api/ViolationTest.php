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

    public function testAttachEvidence_unauthorized_returns403(): void
    {
        $ruleId = $this->ensureRule();

        $loginResp = $this->http->post('/api/auth/login', ['json' => ['username' => 'user1', 'password' => 'User@Campus1!']]);
        $user1Id   = json_decode((string)$loginResp->getBody(), true)['data']['user']['id'];

        // Admin creates violation; user1 is subject, admin is creator
        $createResp = $this->http->post('/api/violations', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['rule_id' => $ruleId, 'subject_user_id' => $user1Id],
        ]);
        $violationId = json_decode((string)$createResp->getBody(), true)['data']['id'];

        // user2 is neither admin, reviewer, the creator, nor the subject — must be denied
        $resp = $this->http->post("/api/violations/{$violationId}/evidence", [
            'headers'   => $this->authHeaders($this->regular2Token()),
            'multipart' => [['name' => 'file', 'contents' => 'fake content', 'filename' => 'evidence.txt']],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testGroupPointSummary_regularUser_returns403(): void
    {
        $resp = $this->http->get('/api/point-summary/groups/1', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Evidence upload — positive path, type rejection, size rejection
    // ---------------------------------------------------------------

    private function createViolationAsAdmin(): int
    {
        $ruleId = $this->ensureRule();
        $loginResp = $this->http->post('/api/auth/login', ['json' => ['username' => 'user1', 'password' => 'User@Campus1!']]);
        $userId    = json_decode((string)$loginResp->getBody(), true)['data']['user']['id'];

        $createResp = $this->http->post('/api/violations', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['rule_id' => $ruleId, 'subject_user_id' => $userId, 'notes' => 'Evidence test'],
        ]);
        $this->assertSame(201, $createResp->getStatusCode());
        return json_decode((string)$createResp->getBody(), true)['data']['id'];
    }

    /**
     * Returns a minimal valid 1×1 PNG binary (67 bytes).
     * Detected by finfo as image/png — passes the MIME whitelist.
     */
    private function minimalPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQ' .
            'AABjkB6QAAAABJRU5ErkJggg=='
        );
    }

    public function testAttachEvidence_validPng_returns201AndPersistsHash(): void
    {
        $violationId = $this->createViolationAsAdmin();
        $pngContent  = $this->minimalPng();

        $resp = $this->http->post("/api/violations/{$violationId}/evidence", [
            'headers'   => $this->authHeaders($this->adminToken()),
            'multipart' => [[
                'name'     => 'file',
                'contents' => $pngContent,
                'filename' => 'evidence.png',
            ]],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('sha256', $body['data'], 'Response must include sha256 fingerprint');
        $this->assertNotEmpty($body['data']['sha256'], 'sha256 must be non-empty');
        $this->assertArrayHasKey('file_path', $body['data'], 'Response must include file_path');
        $this->assertStringContainsString('.png', $body['data']['file_path']);

        // Verify persisted in DB: sha256_hash and file_path must be stored
        $row = $this->dbPdo()->query(
            "SELECT sha256_hash, file_path FROM violation_evidence WHERE violation_id = {$violationId} LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'Evidence record must be persisted in DB');
        $this->assertSame($body['data']['sha256'], $row['sha256_hash'],
            'Stored sha256_hash must match API response');
        $this->assertNotEmpty($row['file_path'], 'Stored file_path must be non-empty');
    }

    public function testAttachEvidence_invalidFileType_returns422(): void
    {
        $violationId = $this->createViolationAsAdmin();

        // Upload a plain text file — not in MIME whitelist (image/jpeg, image/png, application/pdf)
        $resp = $this->http->post("/api/violations/{$violationId}/evidence", [
            'headers'   => $this->authHeaders($this->adminToken()),
            'multipart' => [[
                'name'     => 'file',
                'contents' => 'This is a plain text file with no image magic bytes.',
                'filename' => 'bad.txt',
            ]],
        ]);
        $this->assertSame(422, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertNotEmpty($body['msg'], 'Rejection response must include an error message');
    }

    public function testListRules_authenticated_returns200WithRuleCollection(): void
    {
        $this->ensureRule();
        $resp = $this->http->get('/api/violation-rules', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertIsArray($body['data'] ?? null,
            'Violation rules response must be an array');
        $this->assertNotEmpty($body['data'],
            'At least one rule must be returned after ensureRule()');
    }

    public function testListRules_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/violation-rules');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testUpdateRule_asAdmin_returns200WithUpdatedFields(): void
    {
        $ruleId      = $this->ensureRule();
        $updatedName = 'Updated Rule ' . time();

        $resp = $this->http->put("/api/violation-rules/{$ruleId}", [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['name' => $updatedName, 'point_value' => -15],
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame($updatedName, $body['data']['name'],
            'Updated rule name must be reflected in response');
    }

    public function testUpdateRule_asNonAdmin_returns403(): void
    {
        $ruleId = $this->ensureRule();
        $resp   = $this->http->put("/api/violation-rules/{$ruleId}", [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['name' => 'Hack', 'point_value' => 999],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testDeleteRule_asAdmin_returns200(): void
    {
        // Create a dedicated rule for this test to avoid disrupting shared state
        $createResp = $this->http->post('/api/violation-rules', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['name' => 'Temp Rule ' . time(), 'point_value' => -1],
        ]);
        $this->assertSame(201, $createResp->getStatusCode());
        $tempRuleId = json_decode((string)$createResp->getBody(), true)['data']['id'];

        $resp = $this->http->delete("/api/violation-rules/{$tempRuleId}", [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testDeleteRule_asNonAdmin_returns403(): void
    {
        $ruleId = $this->ensureRule();
        $resp   = $this->http->delete("/api/violation-rules/{$ruleId}", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testShowViolation_existingId_returns200WithMatchingId(): void
    {
        $violationId = $this->createViolationAsAdmin();

        $resp = $this->http->get("/api/violations/{$violationId}", [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame($violationId, $body['data']['id'],
            'Returned violation id must match the requested id');
    }

    public function testShowViolation_missingId_returns404(): void
    {
        $resp = $this->http->get('/api/violations/999999999', [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $this->assertSame(404, $resp->getStatusCode());
    }

    // ── Re-review (PATCH /api/violations/:id/appeals/re-review) ────────────

    private function createViolationWithInitialReview(string $decision = 'approved'): array
    {
        $ruleId    = $this->ensureRule();
        $loginResp = $this->http->post('/api/auth/login', ['json' => ['username' => 'user1', 'password' => 'User@Campus1!']]);
        $userId    = json_decode((string)$loginResp->getBody(), true)['data']['user']['id'];

        $vResp = $this->http->post('/api/violations', [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['rule_id' => $ruleId, 'subject_user_id' => $userId, 'notes' => 'Re-review test'],
        ]);
        $violationId = json_decode((string)$vResp->getBody(), true)['data']['id'];

        $this->http->post("/api/violations/{$violationId}/appeals", [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['reason' => 'I want to appeal'],
        ]);

        $this->http->patch("/api/violations/{$violationId}/appeals/review", [
            'headers' => $this->authHeaders($this->reviewerToken()),
            'json'    => ['decision' => $decision, 'decision_notes' => 'Initial review decision'],
        ]);

        return ['violation_id' => $violationId];
    }

    public function testReReviewAppeal_afterInitialReview_returns200(): void
    {
        $ctx = $this->createViolationWithInitialReview('approved');

        $resp = $this->http->patch("/api/violations/{$ctx['violation_id']}/appeals/re-review", [
            'headers' => $this->authHeaders($this->reviewerToken()),
            'json'    => ['decision' => 'rejected', 'decision_notes' => 'On further review, upholding violation'],
        ]);
        $this->assertSame(200, $resp->getStatusCode(),
            'Re-review must succeed after an initial review exists');
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('review_id', $body['data'],
            'Response must include the new review_id');
        $this->assertSame('rejected', $body['data']['decision'],
            'Response must reflect the submitted re-review decision');
    }

    public function testReReviewAppeal_missingNotes_returns422(): void
    {
        $ctx = $this->createViolationWithInitialReview('rejected');

        $resp = $this->http->patch("/api/violations/{$ctx['violation_id']}/appeals/re-review", [
            'headers' => $this->authHeaders($this->reviewerToken()),
            'json'    => ['decision' => 'approved', 'decision_notes' => ''],
        ]);
        $this->assertSame(422, $resp->getStatusCode(),
            'Re-review without decision_notes must return 422');
    }

    public function testReReviewAppeal_recordsDistinctDecision(): void
    {
        $ctx = $this->createViolationWithInitialReview('approved');

        // Re-review with opposite decision
        $resp = $this->http->patch("/api/violations/{$ctx['violation_id']}/appeals/re-review", [
            'headers' => $this->authHeaders($this->reviewerToken()),
            'json'    => ['decision' => 'rejected', 'decision_notes' => 'Second look: violation upheld'],
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $firstReviewId = json_decode((string)$resp->getBody(), true)['data']['review_id'];

        // Second re-review with approved decision — distinct record created
        $resp2 = $this->http->patch("/api/violations/{$ctx['violation_id']}/appeals/re-review", [
            'headers' => $this->authHeaders($this->reviewerToken()),
            'json'    => ['decision' => 'approved', 'decision_notes' => 'Third look: reversing again'],
        ]);
        $this->assertSame(200, $resp2->getStatusCode());
        $secondReviewId = json_decode((string)$resp2->getBody(), true)['data']['review_id'];

        $this->assertNotSame($firstReviewId, $secondReviewId,
            'Each re-review must produce a distinct review record');
        $this->assertGreaterThan($firstReviewId, $secondReviewId,
            'Later re-review must have a higher review_id');
    }

    public function testAttachEvidence_oversizedFile_returns422(): void
    {
        $violationId = $this->createViolationAsAdmin();

        // Generate content exceeding the 10 MB application limit (10 MB + 1 byte).
        // The PHP upload layer is configured to 50 M in the Dockerfile, so the
        // application-level check in ViolationService is the first rejection.
        $oversized = str_repeat("\x89PNG", (10 * 1024 * 1024 / 4) + 1);

        $resp = $this->http->post("/api/violations/{$violationId}/evidence", [
            'headers'   => $this->authHeaders($this->adminToken()),
            'multipart' => [[
                'name'     => 'file',
                'contents' => $oversized,
                'filename' => 'big.png',
            ]],
            'timeout'   => 60,
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }
}
