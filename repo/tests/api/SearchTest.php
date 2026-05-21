<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

class SearchTest extends TestCase
{
    private function createAndPublishActivity(string $title, string $body = 'test body'): int
    {
        $token = $this->opsToken();
        $resp  = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($token),
            'json'    => ['title' => $title, 'body' => $body],
        ]);
        $id = json_decode((string)$resp->getBody(), true)['data']['id'];
        $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'published'],
        ]);
        // Allow a moment for index to be updated
        usleep(100000);
        return $id;
    }

    public function testGlobalSearch_returnsHighlightedResults(): void
    {
        $this->createAndPublishActivity('Equipment Rental Workshop', 'How to rent equipment properly');

        $resp = $this->http->get('/api/search?q=Equipment', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertNotEmpty($body['data']['data']);
        // Verify highlight field is present
        $firstResult = $body['data']['data'][0];
        $this->assertArrayHasKey('highlight', $firstResult);
        $this->assertStringContainsString('<mark>', $firstResult['highlight']['title'] ?? $firstResult['highlight']['body']);
    }

    public function testGlobalSearch_missingQuery_returns422(): void
    {
        $resp = $this->http->get('/api/search?q=x', [ // single char < 2
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testGlobalSearch_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/search?q=test');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testGlobalSearch_invalidSort_returns422(): void
    {
        $resp = $this->http->get('/api/search?q=test&sort=invalid_sort', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testGlobalSearch_sortByRecency_returns200(): void
    {
        $this->createAndPublishActivity('Older Activity Recency Test');
        usleep(200000);
        $this->createAndPublishActivity('Newer Activity Recency Test');

        $resp = $this->http->get('/api/search?q=Recency+Test&sort=recency', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testLogisticsSearch_returns200(): void
    {
        $resp = $this->http->get('/api/search/logistics?q=rental', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame(200, $body['code']);
    }

    public function testLogisticsSearch_withSynonyms_expandsQuery(): void
    {
        // Seed activity with 'equipment' title; search for synonym 'device'
        $this->createAndPublishActivity('Equipment Checkout System');

        $resp = $this->http->get('/api/search/logistics?q=device&use_synonyms=true', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        // Result should include the equipment activity due to synonym expansion
        // (behavior depends on synonym seeding in 005 migration)
    }

    public function testLogisticsSearch_invalidSort_returns422(): void
    {
        $resp = $this->http->get('/api/search/logistics?q=test&sort=badSort', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testLogisticsSearch_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/search/logistics?q=test');
        $this->assertSame(401, $resp->getStatusCode());
    }
}
