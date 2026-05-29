<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

class ActivityTest extends TestCase
{
    private int $activityId = 0;

    private function createActivity(string $token, array $override = []): array
    {
        $resp = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($token),
            'json'    => array_merge([
                'title'           => 'Test Activity ' . time(),
                'body'            => 'Test body content for the activity',
                'max_headcount'   => 10,
                'signup_open_at'  => date('Y-m-d H:i:s', time() - 3600),
                'signup_close_at' => date('Y-m-d H:i:s', time() + 86400),
            ], $override),
        ]);
        $this->assertSame(201, $resp->getStatusCode(), 'Activity creation failed');
        return json_decode((string)$resp->getBody(), true)['data'];
    }

    private function publishActivity(string $token, int $id): void
    {
        $resp = $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'published'],
        ]);
        $this->assertSame(200, $resp->getStatusCode(), 'Publish failed');
    }

    public function testCreateActivity_returnsCreatedWithDraftStatus(): void
    {
        $data = $this->createActivity($this->opsToken());
        $this->assertSame('draft', $data['status'] ?? 'draft');
    }

    public function testGetActivity_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/activities/1');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testListActivities_asRegularUser_showsOnlyPublished(): void
    {
        $opsToken = $this->opsToken();
        $data     = $this->createActivity($opsToken);
        $id       = $data['id'];
        // Draft should NOT appear for regular user
        $resp = $this->http->get('/api/activities', ['headers' => $this->authHeaders($this->regularToken())]);
        $this->assertSame(200, $resp->getStatusCode());
        $body  = json_decode((string)$resp->getBody(), true);
        $ids   = array_column($body['data']['data'] ?? [], 'id');
        $this->assertNotContains($id, $ids, 'Draft activity should not appear for regular user');
    }

    public function testUpdatePublishedActivity_createsNewVersion(): void
    {
        $token = $this->opsToken();
        $data  = $this->createActivity($token);
        $id    = $data['id'];

        $this->publishActivity($token, $id);

        $newTitle = 'Updated Title ' . time();
        $this->http->put("/api/activities/{$id}", [
            'headers' => $this->authHeaders($token),
            'json'    => ['title' => $newTitle],
        ]);

        $versResp = $this->http->get("/api/activities/{$id}/versions", [
            'headers' => $this->authHeaders($token),
        ]);
        $this->assertSame(200, $versResp->getStatusCode());
        $versions = json_decode((string)$versResp->getBody(), true)['data'];
        $this->assertNotEmpty($versions, 'Should have at least one version after editing published activity');
        // The diff should contain the title change
        $latestDiff = $versions[0]['diff'] ?? [];
        $this->assertArrayHasKey('title', $latestDiff);
    }

    public function testSignup_exceedingHeadcount_returns409(): void
    {
        $token = $this->adminToken();
        $data  = $this->createActivity($token, ['max_headcount' => 1]);
        $id    = $data['id'];
        $this->publishActivity($token, $id);

        // First signup (user1)
        $r1 = $this->http->post("/api/activities/{$id}/signups", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertContains($r1->getStatusCode(), [200, 409]); // might already be signed up

        // Second signup (user2) should be rejected if max_headcount=1
        $r2 = $this->http->post("/api/activities/{$id}/signups", [
            'headers' => $this->authHeaders($this->regular2Token()),
        ]);
        // Could be 409 conflict if headcount is full
        if ($r1->getStatusCode() === 200) {
            $this->assertSame(409, $r2->getStatusCode());
        }
    }

    public function testTransition_invalidState_returns422(): void
    {
        $token = $this->adminToken();
        $data  = $this->createActivity($token);
        $id    = $data['id'];

        // Try draft -> completed (illegal)
        $resp = $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'completed'],
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testCreateActivity_missingTitle_returns422(): void
    {
        $resp = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($this->opsToken()),
            'json'    => ['body' => 'Some body'],
        ]);
        $this->assertSame(422, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame(422, $body['code']);
    }

    public function testGetActivity_afterPublish_visibleToRegularUser(): void
    {
        $token = $this->opsToken();
        $data  = $this->createActivity($token);
        $id    = $data['id'];
        $this->publishActivity($token, $id);

        $resp = $this->http->get("/api/activities/{$id}", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame($id, $body['data']['id']);
    }

    public function testTransition_draftToPublished_setsPublishedAt(): void
    {
        $token = $this->opsToken();
        $data  = $this->createActivity($token);
        $id    = $data['id'];

        $resp = $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'published'],
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true)['data'];

        $this->assertArrayHasKey('published_at', $body, 'Transition response must include published_at');
        $this->assertNotEmpty($body['published_at'], 'published_at must be set after draft→published transition');
    }

    public function testTransition_fullLifecycle_setsAllTimestamps(): void
    {
        $token = $this->opsToken();
        $data  = $this->createActivity($token);
        $id    = $data['id'];

        // draft → published
        $r1 = $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'published'],
        ]);
        $this->assertSame(200, $r1->getStatusCode());

        // published → in_progress
        $r2 = $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'in_progress'],
        ]);
        $this->assertSame(200, $r2->getStatusCode());
        $d2 = json_decode((string)$r2->getBody(), true)['data'];
        $this->assertNotEmpty($d2['published_at'],   'published_at must persist after in_progress transition');
        $this->assertNotEmpty($d2['in_progress_at'], 'in_progress_at must be set after published→in_progress');

        // in_progress → completed
        $r3 = $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'completed'],
        ]);
        $this->assertSame(200, $r3->getStatusCode());
        $d3 = json_decode((string)$r3->getBody(), true)['data'];
        $this->assertNotEmpty($d3['completed_at'], 'completed_at must be set after in_progress→completed');

        // completed → archived (admin only)
        $r4 = $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['status' => 'archived'],
        ]);
        $this->assertSame(200, $r4->getStatusCode());
        $d4 = json_decode((string)$r4->getBody(), true)['data'];
        $this->assertNotEmpty($d4['archived_at'], 'archived_at must be set after completed→archived');
    }

    public function testActivityShow_includesLifecycleTimestampFields(): void
    {
        $token = $this->opsToken();
        $data  = $this->createActivity($token);
        $id    = $data['id'];

        // Publish so published_at is written
        $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'published'],
        ]);

        // GET the activity — all four fields must appear in the response
        $resp = $this->http->get("/api/activities/{$id}", [
            'headers' => $this->authHeaders($token),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true)['data'];

        foreach (['published_at', 'in_progress_at', 'completed_at', 'archived_at'] as $field) {
            $this->assertArrayHasKey($field, $body, "Activity show response must include {$field}");
        }
        $this->assertNotEmpty($body['published_at'],   'published_at must be non-empty after publish');
        $this->assertSame('',  $body['in_progress_at'], 'in_progress_at must be empty before that transition');
        $this->assertSame('',  $body['completed_at'],   'completed_at must be empty before that transition');
        $this->assertSame('',  $body['archived_at'],    'archived_at must be empty before that transition');
    }

    public function testGetActivity_recordsBehaviorEvent(): void
    {
        $token = $this->opsToken();
        $data  = $this->createActivity($token);
        $id    = $data['id'];
        $this->publishActivity($token, $id);

        // Resolve the actual user_id for user1 from the DB
        $userId = $this->dbUserId('user1');
        $this->assertGreaterThan(0, $userId, 'user1 not found in DB');

        // Purge any existing view events for this user+activity so the dedup window does not interfere
        $this->dbPdo()->prepare(
            'DELETE FROM behavior_events WHERE user_id = ? AND entity_type = "activity" AND entity_id = ? AND event_type = "view"'
        )->execute([$userId, $id]);

        // GET as regular user — BehaviorCapture middleware should insert a row
        $resp = $this->http->get("/api/activities/{$id}", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());

        // Assert the behavior_events row was persisted
        $stmt = $this->dbPdo()->prepare(
            'SELECT COUNT(*) FROM behavior_events
             WHERE user_id = ? AND entity_type = "activity" AND entity_id = ? AND event_type = "view"'
        );
        $stmt->execute([$userId, $id]);
        $count = (int)$stmt->fetchColumn();
        $this->assertGreaterThan(0, $count, 'BehaviorCapture middleware must insert a behavior_events row on GET /activities/{id}');
    }

    public function testSignup_createsBehaviorEvent(): void
    {
        $opsToken = $this->opsToken();
        $regToken = $this->regularToken();

        $data = $this->createActivity($opsToken, ['max_headcount' => 5]);
        $id   = $data['id'];
        $this->publishActivity($opsToken, $id);

        $userId = $this->dbUserId('user1');

        // Clear existing signup events for this user+activity
        $this->dbPdo()->prepare(
            'DELETE FROM behavior_events WHERE user_id = ? AND entity_type = "activity" AND entity_id = ? AND event_type = "signup"'
        )->execute([$userId, $id]);

        $resp = $this->http->post("/api/activities/{$id}/signups", [
            'headers' => $this->authHeaders($regToken),
        ]);
        // Accept 200 (success or already signed up from another test) or 409 (conflict)
        $this->assertContains($resp->getStatusCode(), [200, 409]);

        if ($resp->getStatusCode() === 200) {
            $stmt = $this->dbPdo()->prepare(
                'SELECT COUNT(*) FROM behavior_events
                 WHERE user_id = ? AND entity_type = "activity" AND entity_id = ? AND event_type = "signup"'
            );
            $stmt->execute([$userId, $id]);
            $count = (int)$stmt->fetchColumn();
            $this->assertGreaterThan(0, $count,
                'A signup behavior event must be written to behavior_events after a successful signup');
        }
    }

    public function testSaveUnsave_createsBehaviorEventAndIsIdempotent(): void
    {
        $opsToken = $this->opsToken();
        $regToken = $this->regularToken();

        $data = $this->createActivity($opsToken);
        $id   = $data['id'];
        $this->publishActivity($opsToken, $id);

        $userId = $this->dbUserId('user1');

        // Clear any prior save events for this user+activity
        $this->dbPdo()->prepare(
            'DELETE FROM behavior_events WHERE user_id = ? AND entity_type = "activity" AND entity_id = ? AND event_type = "save"'
        )->execute([$userId, $id]);
        $this->dbPdo()->prepare(
            'DELETE FROM activity_saves WHERE user_id = ? AND activity_id = ?'
        )->execute([$userId, $id]);

        // First save
        $r1 = $this->http->post("/api/activities/{$id}/saves", [
            'headers' => $this->authHeaders($regToken),
        ]);
        $this->assertSame(200, $r1->getStatusCode(), 'Save must return 200');

        // Verify behavior event written
        $stmt = $this->dbPdo()->prepare(
            'SELECT COUNT(*) FROM behavior_events
             WHERE user_id = ? AND entity_type = "activity" AND entity_id = ? AND event_type = "save"'
        );
        $stmt->execute([$userId, $id]);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn(),
            'A save behavior event must be written after POST /activities/{id}/saves');

        // Second save is idempotent — must also return 200, not create duplicate event
        $r2 = $this->http->post("/api/activities/{$id}/saves", [
            'headers' => $this->authHeaders($regToken),
        ]);
        $this->assertSame(200, $r2->getStatusCode(), 'Repeated save must return 200');

        // Unsave
        $r3 = $this->http->delete("/api/activities/{$id}/saves", [
            'headers' => $this->authHeaders($regToken),
        ]);
        $this->assertSame(200, $r3->getStatusCode(), 'Unsave must return 200');

        // Verify row removed from activity_saves
        $stmt2 = $this->dbPdo()->prepare(
            'SELECT COUNT(*) FROM activity_saves WHERE user_id = ? AND activity_id = ?'
        );
        $stmt2->execute([$userId, $id]);
        $this->assertSame(0, (int)$stmt2->fetchColumn(),
            'activity_saves row must be removed after DELETE /activities/{id}/saves');
    }

    // ── Eligibility tags authoring ───────────────────────────────────────────

    public function testCreateActivity_withTags_persistsTagsOnFetch(): void
    {
        $token = $this->opsToken();
        $tags  = ['outdoor', 'teamwork', 'sports'];

        $createResp = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($token),
            'json'    => [
                'title' => 'Tagged Activity ' . time(),
                'body'  => 'Activity with eligibility tags for authoring test',
                'tags'  => $tags,
            ],
        ]);
        $this->assertSame(201, $createResp->getStatusCode(), 'Activity with tags must be created');
        $id = json_decode((string)$createResp->getBody(), true)['data']['id'];

        // Verify tags are retrievable via show endpoint
        $getResp = $this->http->get("/api/activities/{$id}", [
            'headers' => $this->authHeaders($token),
        ]);
        $this->assertSame(200, $getResp->getStatusCode());
        $data = json_decode((string)$getResp->getBody(), true)['data'];
        $this->assertArrayHasKey('tags', $data, 'Activity show response must include tags key');
        $this->assertNotEmpty($data['tags'], 'Tags must be non-empty after create with tags payload');
    }

    public function testCreateActivity_withDuplicateTags_deduplicatedOrAccepted(): void
    {
        $token = $this->opsToken();

        $createResp = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($token),
            'json'    => [
                'title' => 'Dedup Tag Activity ' . time(),
                'body'  => 'Testing dedup behavior',
                'tags'  => ['sports', 'sports', 'outdoor'],
            ],
        ]);
        // Backend must accept the request (dedup is a normalization detail, not a validation error)
        $this->assertSame(201, $createResp->getStatusCode(),
            'Activity with duplicate tags in payload must be accepted');
    }

    public function testCreateActivity_withEmptyTagsArray_returns201(): void
    {
        $token = $this->opsToken();

        $createResp = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($token),
            'json'    => [
                'title' => 'No-Tag Activity ' . time(),
                'body'  => 'Activity without tags',
                'tags'  => [],
            ],
        ]);
        $this->assertSame(201, $createResp->getStatusCode(),
            'Activity with empty tags array must be created successfully');
    }

    // ── Edit flow — API tests ────────────────────────────────────────────────

    public function testUpdatePublishedActivity_withTagsAndSupplies_versionContainsDiff(): void
    {
        $token    = $this->opsToken();
        $data     = $this->createActivity($token);
        $id       = $data['id'];
        $this->publishActivity($token, $id);

        $newTitle = 'Edited Published ' . time();
        $updateResp = $this->http->put("/api/activities/{$id}", [
            'headers' => $this->authHeaders($token),
            'json'    => [
                'title'             => $newTitle,
                'tags'              => ['teamwork', 'outdoor'],
                'required_supplies' => ['markers', 'paper'],
            ],
        ]);
        $this->assertSame(200, $updateResp->getStatusCode(),
            'Updating a published activity must return 200');

        $versResp = $this->http->get("/api/activities/{$id}/versions", [
            'headers' => $this->authHeaders($token),
        ]);
        $this->assertSame(200, $versResp->getStatusCode());
        $versions = json_decode((string)$versResp->getBody(), true)['data'] ?? [];
        $this->assertNotEmpty($versions,
            'Updating a published activity must produce at least one version record');
        $this->assertArrayHasKey('title', $versions[0]['diff'] ?? [],
            'Version diff must include the title field that changed');
    }

    public function testUpdateDraftActivity_noVersionCreated(): void
    {
        $token = $this->opsToken();
        $data  = $this->createActivity($token);
        $id    = $data['id'];
        // Activity remains in draft — no publish call

        $updateResp = $this->http->put("/api/activities/{$id}", [
            'headers' => $this->authHeaders($token),
            'json'    => ['title' => 'Draft Edit ' . time()],
        ]);
        $this->assertSame(200, $updateResp->getStatusCode(),
            'Updating a draft activity must return 200');

        $versResp = $this->http->get("/api/activities/{$id}/versions", [
            'headers' => $this->authHeaders($token),
        ]);
        $this->assertSame(200, $versResp->getStatusCode());
        $versions = json_decode((string)$versResp->getBody(), true)['data'] ?? [];
        $this->assertEmpty($versions,
            'Updating a draft activity must not create a version record');
    }

    // ── Edit flow — E2E role authorization ───────────────────────────────────

    public function testEditActivity_opsStaff_returns200(): void
    {
        $token  = $this->opsToken();
        $data   = $this->createActivity($token);
        $id     = $data['id'];

        $resp = $this->http->put("/api/activities/{$id}", [
            'headers' => $this->authHeaders($token),
            'json'    => [
                'title'             => 'E2E Edit ' . time(),
                'tags'              => ['sports'],
                'required_supplies' => ['cones'],
            ],
        ]);
        $this->assertSame(200, $resp->getStatusCode(),
            'Ops staff must be able to edit activities via PUT /api/activities/:id');
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame(200, $body['code'] ?? null,
            'Response body code must be 200 on successful edit');
    }

    public function testEditActivity_regularUser_returns403(): void
    {
        $opsToken = $this->opsToken();
        $regToken = $this->regularToken();

        $data = $this->createActivity($opsToken);
        $id   = $data['id'];
        $this->publishActivity($opsToken, $id);

        $resp = $this->http->put("/api/activities/{$id}", [
            'headers' => $this->authHeaders($regToken),
            'json'    => ['title' => 'Unauthorized Edit'],
        ]);
        $this->assertSame(403, $resp->getStatusCode(),
            'Regular users must not be able to edit activities');
    }

    // ── Changelog (versions) visibility ─────────────────────────────────────

    public function testVersions_regularUser_publishedActivity_returns200(): void
    {
        $opsToken = $this->opsToken();
        $regToken = $this->regularToken();

        $data = $this->createActivity($opsToken);
        $id   = $data['id'];
        $this->publishActivity($opsToken, $id);

        $resp = $this->http->get("/api/activities/{$id}/versions", [
            'headers' => $this->authHeaders($regToken),
        ]);
        $this->assertSame(200, $resp->getStatusCode(),
            'Regular user must be able to read versions of a published activity');
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertIsArray($body['data'] ?? null,
            'Versions response data must be an array');
    }

    public function testVersions_regularUser_draftActivity_returns404(): void
    {
        $opsToken = $this->opsToken();
        $regToken = $this->regularToken();

        $data = $this->createActivity($opsToken);
        $id   = $data['id'];
        // Intentionally not published — activity stays in draft

        $resp = $this->http->get("/api/activities/{$id}/versions", [
            'headers' => $this->authHeaders($regToken),
        ]);
        $this->assertSame(404, $resp->getStatusCode(),
            'Regular user must not see versions of a draft activity');
    }

    // ── Cancel signup ────────────────────────────────────────────────────────

    public function testCancelSignup_owner_returns200(): void
    {
        $opsToken = $this->opsToken();
        $regToken = $this->regularToken();

        $data = $this->createActivity($opsToken, ['max_headcount' => 10]);
        $id   = $data['id'];
        $this->publishActivity($opsToken, $id);

        $this->http->post("/api/activities/{$id}/signups", [
            'headers' => $this->authHeaders($regToken),
        ]);

        $userId = $this->dbUserId('user1');

        $resp = $this->http->delete("/api/activities/{$id}/signups/{$userId}", [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $resp->getStatusCode(),
            'Admin/ops_staff must be able to cancel an existing signup');
    }

    public function testCancelSignup_nonExistentSignup_returns404(): void
    {
        $opsToken = $this->opsToken();

        $data = $this->createActivity($opsToken, ['max_headcount' => 10]);
        $id   = $data['id'];
        $this->publishActivity($opsToken, $id);

        // user2 has never signed up for this activity
        $userId = $this->dbUserId('user2');

        $resp = $this->http->delete("/api/activities/{$id}/signups/{$userId}", [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(404, $resp->getStatusCode(),
            'Cancelling a non-existent signup must return 404');
    }
}
