<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

class RecommendationTest extends TestCase
{
    private function createTaggedActivity(string $token, string $title, array $tags): int
    {
        $resp = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($token),
            'json'    => ['title' => $title, 'body' => 'body', 'tags' => $tags],
        ]);
        $id = json_decode((string)$resp->getBody(), true)['data']['id'];
        $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'published'],
        ]);
        return $id;
    }

    public function testListRecommendations_returns200(): void
    {
        $resp = $this->http->get('/api/recommendations', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('items', $body['data']);
        $this->assertArrayHasKey('is_cold_start', $body['data']);
    }

    public function testListRecommendations_coldStart_returnsItems(): void
    {
        // Create activities with known tags
        $token = $this->adminToken();
        $this->createTaggedActivity($token, 'Sports Day Event', ['sports', 'fitness']);
        $this->createTaggedActivity($token, 'Music Concert', ['music', 'arts']);

        // Seed tag_popularity for sports
        \think\facade\Db::table('tag_popularity')->insertOrUpdate(
            ['tag' => 'sports', 'period_start' => date('Y-m-d')],
            ['signup_count' => 10, 'view_count' => 50, 'score' => 26.0]
        );

        $resp = $this->http->get('/api/recommendations', [
            'headers' => $this->authHeaders($this->regular2Token()), // user with no history
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('items', $body['data']);
    }

    public function testDetailRecommendations_excludesSelf(): void
    {
        $token  = $this->adminToken();
        $selfId = $this->createTaggedActivity($token, 'Self Activity', ['tech']);

        $resp = $this->http->get("/api/recommendations/activities/{$selfId}", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'];
        $ids   = array_column($items, 'entity_id');
        $this->assertNotContains($selfId, $ids, 'The activity itself should be excluded from detail recommendations');
    }

    public function testDiversityCap_singleTag_notOver40Percent(): void
    {
        $token = $this->adminToken();
        // Create 10 sports activities and 5 music activities
        for ($i = 0; $i < 10; $i++) {
            $this->createTaggedActivity($token, "Sports Activity {$i}", ['sports']);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->createTaggedActivity($token, "Music Activity {$i}", ['music']);
        }

        $resp  = $this->http->get('/api/recommendations?page_size=10', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $items = json_decode((string)$resp->getBody(), true)['data']['items'];

        if (count($items) >= 5) {
            $sportCount = 0;
            foreach ($items as $item) {
                if (in_array('sports', $item['tags'] ?? [], true)) $sportCount++;
            }
            $percent = $sportCount / count($items);
            $this->assertLessThanOrEqual(0.40 + 0.01, $percent, "Sports tag exceeds 40% diversity cap: {$sportCount}/" . count($items));
        }
    }

    public function testRecommendations_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/recommendations');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testDetailRecommendations_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/recommendations/activities/1');
        $this->assertSame(401, $resp->getStatusCode());
    }
}
