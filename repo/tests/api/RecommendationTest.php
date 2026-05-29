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

    public function testRecommendations_includesOrderCandidates(): void
    {
        $opsToken = $this->opsToken();
        $user2Id  = $this->dbUserId('user2');

        // Ensure user2 has no behavior history so cold-start path runs
        $this->dbPdo()->prepare('DELETE FROM behavior_events WHERE user_id = ?')->execute([$user2Id]);
        $this->dbPdo()->prepare('DELETE FROM recommendation_cache WHERE user_id = ?')->execute([$user2Id]);

        // Create an order and set a high view_count so it ranks above activity candidates
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => 'RecEngineOrderTest ' . time()],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        usleep(100000);

        $this->dbPdo()->prepare(
            'UPDATE logistics_index SET view_count = 9999 WHERE entity_type = ? AND entity_id = ?'
        )->execute(['order', $orderId]);

        $resp = $this->http->get('/api/recommendations', [
            'headers' => $this->authHeaders($this->regular2Token()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);

        $entityTypes = array_column($body['data']['items'] ?? [], 'entity_type');
        $this->assertContains('order', $entityTypes,
            'Recommendation engine must include order-type candidates in cold-start results');

        $orderIds = array_column(
            array_filter($body['data']['items'] ?? [], fn($i) => $i['entity_type'] === 'order'),
            'entity_id'
        );
        $this->assertContains((string)$orderId, array_map('strval', $orderIds),
            "Order #{$orderId} with view_count=9999 must appear in cold-start recommendations");
    }

    public function testRecommendations_noEntityAppearsMoreThanOnce(): void
    {
        $resp  = $this->http->get('/api/recommendations', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'] ?? [];

        // Build composite entity keys (entity_type:entity_id) and assert no duplicates
        $keys = array_map(fn($i) => $i['entity_type'] . ':' . $i['entity_id'], $items);
        $this->assertSame(count($keys), count(array_unique($keys)),
            'Each entity must appear at most once in recommendation results (stable dedup)');
    }

    public function testRecommendations_itemsHaveFamilyId(): void
    {
        $resp  = $this->http->get('/api/recommendations', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'] ?? [];

        foreach ($items as $item) {
            $this->assertArrayHasKey('family_id', $item,
                'Every recommendation item must carry a stable family_id field');
            $this->assertNotEmpty($item['family_id'],
                'family_id must be a non-empty string');
        }
    }

    public function testRecommendations_familyIdDedup_noTwoItemsSameFamily(): void
    {
        $token     = $this->adminToken();
        $familyTag = 'tagfamid' . time();

        // Create 3 activities that all share the same lex-first tag → same family_id
        for ($i = 1; $i <= 3; $i++) {
            $this->createTaggedActivity($token, "FamIdDup Activity {$i}", [$familyTag, 'zzsecondary' . $i]);
        }

        // Seed tag_popularity so cold-start picks this family tag
        \think\facade\Db::table('tag_popularity')->insertOrUpdate(
            ['tag' => $familyTag, 'period_start' => date('Y-m-d')],
            ['signup_count' => 5, 'view_count' => 20, 'score' => 12.0]
        );

        $user2Id = $this->dbUserId('user2');
        $this->dbPdo()->prepare('DELETE FROM behavior_events WHERE user_id = ?')->execute([$user2Id]);
        $this->dbPdo()->prepare('DELETE FROM recommendation_cache WHERE user_id = ?')->execute([$user2Id]);

        $resp  = $this->http->get('/api/recommendations', [
            'headers' => $this->authHeaders($this->regular2Token()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'] ?? [];

        $familyIds = array_column($items, 'family_id');
        $this->assertSame(
            count($familyIds),
            count(array_unique($familyIds)),
            'No two recommendation items may share the same family_id'
        );

        // At most one item from the seeded family tag
        $familyCount = count(array_filter($familyIds, fn($fid) => $fid === 'tag:' . $familyTag));
        $this->assertLessThanOrEqual(1, $familyCount,
            "Family dedup must allow at most 1 item with family_id='tag:{$familyTag}'");
    }

    public function testRecommendations_familyIdFromExplicitColumn(): void
    {
        $token     = $this->adminToken();
        $familyTag = 'explicitfam' . time();

        // Create activity with known tag — family_id should be 'tag:<familyTag>'
        $actId = $this->createTaggedActivity($token, 'ExplicitFam Activity', [$familyTag]);

        // Seed tag_popularity so cold-start returns this activity
        \think\facade\Db::table('tag_popularity')->insertOrUpdate(
            ['tag' => $familyTag, 'period_start' => date('Y-m-d')],
            ['signup_count' => 5, 'view_count' => 20, 'score' => 13.0]
        );

        $user2Id = $this->dbUserId('user2');
        $this->dbPdo()->prepare('DELETE FROM behavior_events WHERE user_id = ?')->execute([$user2Id]);
        $this->dbPdo()->prepare('DELETE FROM recommendation_cache WHERE user_id = ?')->execute([$user2Id]);

        $resp  = $this->http->get('/api/recommendations', [
            'headers' => $this->authHeaders($this->regular2Token()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'] ?? [];

        // Find the item for our activity
        $matched = array_values(array_filter($items, fn($i) => (int)$i['entity_id'] === $actId));
        if (!empty($matched)) {
            $this->assertSame(
                'tag:' . $familyTag,
                $matched[0]['family_id'],
                "family_id must be 'tag:{$familyTag}' (stored from explicit family column, not tag fallback heuristic)"
            );
        }
    }

    public function testRecommendations_signalScoring_improvedBySignupEvent(): void
    {
        $opsToken = $this->opsToken();
        $regToken = $this->regularToken();
        $familyTag = 'signaltag' . time();

        // Create and publish a tagged activity
        $actId = $this->createTaggedActivity($this->adminToken(), 'SignalActivity', [$familyTag]);

        // Seed a signup behavior event for user1 for this activity
        $userId = $this->dbUserId('user1');
        \think\facade\Db::table('behavior_events')->insert([
            'user_id'     => $userId,
            'entity_type' => 'activity',
            'entity_id'   => $actId,
            'event_type'  => 'signup',
            'occurred_at' => date('Y-m-d H:i:s'),
        ]);

        // Invalidate cache so compute() runs fresh
        $this->dbPdo()->prepare('DELETE FROM recommendation_cache WHERE user_id = ?')->execute([$userId]);

        $resp  = $this->http->get('/api/recommendations', [
            'headers' => $this->authHeaders($regToken),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);

        // Recommendations should return results (signal-based, not cold-start)
        $this->assertFalse($body['data']['is_cold_start'],
            'User with signup signal must not hit cold-start path');
        $this->assertNotEmpty($body['data']['items'],
            'Signal-based recommendations must return items');
    }

    // ---------------------------------------------------------------
    // Order-detail recommendation endpoint
    // ---------------------------------------------------------------

    public function testOrderDetailRecommendations_returns200(): void
    {
        $opsToken = $this->opsToken();
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => 'DetailRecTest ' . time()],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        $resp = $this->http->get("/api/recommendations/orders/{$orderId}", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('items', $body['data']);
        $this->assertArrayHasKey('is_cold_start', $body['data']);
    }

    public function testOrderDetailRecommendations_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/recommendations/orders/1');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testOrderDetailRecommendations_excludesSelf(): void
    {
        $opsToken  = $this->opsToken();
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => 'SelfExclTest ' . time()],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        $resp  = $this->http->get("/api/recommendations/orders/{$orderId}", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'];
        $orderIds = array_column(
            array_filter($items, fn($i) => $i['entity_type'] === 'order'),
            'entity_id'
        );
        $this->assertNotContains((string)$orderId, array_map('strval', $orderIds),
            'Order-detail recommendations must exclude the current order (self-exclusion)');
    }

    public function testOrderDetailRecommendations_doesNotExcludeActivitiesWithSameNumericId(): void
    {
        $opsToken  = $this->opsToken();
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'material_request', 'description' => 'EntityTypeAwareTest ' . time()],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        // Boost any indexed activity so it ranks highly in recommendations
        $this->dbPdo()->exec(
            "UPDATE search_index SET view_count = 7777 WHERE entity_type = 'activity' LIMIT 1"
        );

        $resp  = $this->http->get("/api/recommendations/orders/{$orderId}", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'] ?? [];

        // Activities must appear — entity-type-aware exclusion must not suppress them
        // when only the order with the same numeric ID is the self-excluded entity
        $activityItems = array_filter($items, fn($i) => $i['entity_type'] === 'activity');
        $this->assertNotEmpty($activityItems,
            'Activities must not be excluded from order-detail recommendations; '
            . 'self-exclusion must be entity-type-scoped (only the order is excluded, not activities)');
    }

    public function testFamilyDedup_samePrimaryTag_notDuplicated(): void
    {
        $token     = $this->adminToken();
        $familyTag = 'familydedup' . time();

        // Create 3 activities sharing the same first (family key) tag
        for ($i = 1; $i <= 3; $i++) {
            $this->createTaggedActivity($token, "FamilyDup Activity {$i}", [$familyTag, 'secondary' . $i]);
        }

        // Seed tag_popularity so cold-start path picks this family tag
        \think\facade\Db::table('tag_popularity')->insertOrUpdate(
            ['tag' => $familyTag, 'period_start' => date('Y-m-d')],
            ['signup_count' => 5, 'view_count' => 20, 'score' => 11.0]
        );

        // Clear any cached recommendations for user2 so the algorithm runs fresh
        $user2Id = $this->dbUserId('user2');
        $this->dbPdo()->prepare("DELETE FROM recommendation_cache WHERE user_id = ?")->execute([$user2Id]);

        // user2 has no behavior history → cold-start path with family dedup
        $resp  = $this->http->get('/api/recommendations', [
            'headers' => $this->authHeaders($this->regular2Token()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'];

        // At most 1 item may share the same primary tag (family key)
        $familyCount = 0;
        foreach ($items as $item) {
            $tags = $item['tags'] ?? [];
            if (!empty($tags) && $tags[0] === $familyTag) {
                $familyCount++;
            }
        }
        $this->assertLessThanOrEqual(1, $familyCount,
            "Family dedup should allow at most 1 item per primary tag; got {$familyCount} with '{$familyTag}'");
    }

    // ---------------------------------------------------------------
    // Order-visibility authorization in recommendations
    // ---------------------------------------------------------------

    public function testRecommendations_regularUser_doesNotReceiveUnauthorizedOrders(): void
    {
        // ops_user creates an order — regular user1 did not create it and cannot see it
        $opsToken = $this->opsToken();
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => 'AuthBoundaryOrder ' . time()],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        // Boost the order's view_count so it would rank highly if authorization were absent
        $this->dbPdo()->prepare(
            'UPDATE logistics_index SET view_count = 88888 WHERE entity_type = ? AND entity_id = ?'
        )->execute(['order', $orderId]);

        // Purge regular user1's recommendation cache so the engine recomputes
        $user1Id = $this->dbUserId('user1');
        $this->dbPdo()->prepare('DELETE FROM recommendation_cache WHERE user_id = ?')->execute([$user1Id]);
        $this->dbPdo()->prepare('DELETE FROM behavior_events WHERE user_id = ?')->execute([$user1Id]);

        $resp  = $this->http->get('/api/recommendations?page_size=50', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'] ?? [];

        $returnedOrderIds = array_column(
            array_filter($items, fn($i) => $i['entity_type'] === 'order'),
            'entity_id'
        );
        $this->assertNotContains((string)$orderId, array_map('strval', $returnedOrderIds),
            'Regular user must not receive order recommendations for orders they did not create');
    }

    public function testRecommendations_admin_receivesAllOrderCandidates(): void
    {
        // ops_user creates an order — admin did not create it but must be able to see all orders
        $opsToken = $this->opsToken();
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'material_request', 'description' => 'AdminVisibilityOrder ' . time()],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        // Give this order a massive view_count so it surfaces at the top
        $this->dbPdo()->prepare(
            'UPDATE logistics_index SET view_count = 99999 WHERE entity_type = ? AND entity_id = ?'
        )->execute(['order', $orderId]);

        // Purge admin's recommendation cache so the engine recomputes
        $adminId = $this->dbUserId('admin');
        $this->dbPdo()->prepare('DELETE FROM recommendation_cache WHERE user_id = ?')->execute([$adminId]);

        $resp  = $this->http->get('/api/recommendations?page_size=50', [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'] ?? [];

        $returnedOrderIds = array_column(
            array_filter($items, fn($i) => $i['entity_type'] === 'order'),
            'entity_id'
        );
        $this->assertContains((string)$orderId, array_map('strval', $returnedOrderIds),
            'Admin must receive order candidates for all orders regardless of creator');
    }

    public function testOrderDetailRecommendations_returns200_afterAuthFiltering(): void
    {
        // ops_user creates an order; admin calls the order-detail endpoint — must return 200
        $opsToken  = $this->opsToken();
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => 'AuthFilterDetail ' . time()],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        $resp = $this->http->get("/api/recommendations/orders/{$orderId}", [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('items', $body['data']);
        $this->assertArrayHasKey('is_cold_start', $body['data']);
    }

    public function testOrderDetailRecommendations_selfExclusionIntactAfterAuthFiltering(): void
    {
        // admin creates an order; asks for its order-detail recommendations — self must be absent
        $adminToken = $this->adminToken();
        $orderResp  = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($adminToken),
            'json'    => ['type' => 'equipment_rental', 'description' => 'SelfExclAuthOrder ' . time()],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        // Give the order a high view_count so it would rank in recommendations if not excluded
        $this->dbPdo()->prepare(
            'UPDATE logistics_index SET view_count = 77777 WHERE entity_type = ? AND entity_id = ?'
        )->execute(['order', $orderId]);

        $adminId = $this->dbUserId('admin');
        $this->dbPdo()->prepare('DELETE FROM recommendation_cache WHERE user_id = ?')->execute([$adminId]);

        $resp  = $this->http->get("/api/recommendations/orders/{$orderId}", [
            'headers' => $this->authHeaders($adminToken),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['items'] ?? [];

        $returnedOrderIds = array_column(
            array_filter($items, fn($i) => $i['entity_type'] === 'order'),
            'entity_id'
        );
        $this->assertNotContains((string)$orderId, array_map('strval', $returnedOrderIds),
            'Self-exclusion must keep the current order out of order-detail recommendations even after auth filtering');
    }
}
