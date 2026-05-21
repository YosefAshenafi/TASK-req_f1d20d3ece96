<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

class TaskTest extends TestCase
{
    private function createTestActivity(): int
    {
        $resp = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($this->opsToken()),
            'json'    => ['title' => 'Task Test Activity', 'body' => 'body'],
        ]);
        return json_decode((string)$resp->getBody(), true)['data']['id'];
    }

    public function testCreateTask_asTeamLead_returns201(): void
    {
        $actId = $this->createTestActivity();
        $resp  = $this->http->post("/api/activities/{$actId}/tasks", [
            'headers' => $this->authHeaders($this->teamLeadToken()),
            'json'    => ['title' => 'Setup chairs', 'staffing_count' => 3],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame('Setup chairs', $body['data']['title']);
    }

    public function testCreateTask_asRegularUser_returns403(): void
    {
        $actId = $this->createTestActivity();
        $resp  = $this->http->post("/api/activities/{$actId}/tasks", [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['title' => 'Hack task'],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testCreateTask_missingTitle_returns422(): void
    {
        $actId = $this->createTestActivity();
        $resp  = $this->http->post("/api/activities/{$actId}/tasks", [
            'headers' => $this->authHeaders($this->teamLeadToken()),
            'json'    => ['staffing_count' => 2],
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testUpdateTask_asTeamLead_returns200(): void
    {
        $actId  = $this->createTestActivity();
        $create = $this->http->post("/api/activities/{$actId}/tasks", [
            'headers' => $this->authHeaders($this->teamLeadToken()),
            'json'    => ['title' => 'Old title'],
        ]);
        $taskId = json_decode((string)$create->getBody(), true)['data']['id'];

        $resp = $this->http->put("/api/activities/{$actId}/tasks/{$taskId}", [
            'headers' => $this->authHeaders($this->teamLeadToken()),
            'json'    => ['title' => 'New title', 'status' => 'in_progress'],
        ]);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testDeleteTask_asTeamLead_returns200(): void
    {
        $actId  = $this->createTestActivity();
        $create = $this->http->post("/api/activities/{$actId}/tasks", [
            'headers' => $this->authHeaders($this->teamLeadToken()),
            'json'    => ['title' => 'To delete'],
        ]);
        $taskId = json_decode((string)$create->getBody(), true)['data']['id'];

        $resp = $this->http->delete("/api/activities/{$actId}/tasks/{$taskId}", [
            'headers' => $this->authHeaders($this->teamLeadToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testGetTasks_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/activities/1/tasks');
        $this->assertSame(401, $resp->getStatusCode());
    }
}
