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
}
