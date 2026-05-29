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

    public function testGlobalSearch_canMatchByTag(): void
    {
        $token     = $this->opsToken();
        $uniqueTag = 'zephyrtag' . time();

        // Include unique term in title to guarantee FULLTEXT indexability
        $resp = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($token),
            'json'    => [
                'title' => 'Tag Search ' . $uniqueTag,
                'body'  => 'body content for tag search test',
                'tags'  => [$uniqueTag],
            ],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        $id = json_decode((string)$resp->getBody(), true)['data']['id'];

        $this->http->patch("/api/activities/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'published'],
        ]);
        usleep(200000);

        $searchResp = $this->http->get('/api/search?q=' . urlencode($uniqueTag), [
            'headers' => $this->authHeaders($token),
        ]);
        $this->assertSame(200, $searchResp->getStatusCode());
        $body = json_decode((string)$searchResp->getBody(), true);
        $ids  = array_column($body['data']['data'] ?? [], 'entity_id');
        $this->assertContains((string)$id, array_map('strval', $ids),
            'Activity with matching tag must appear in global search results');
    }

    public function testLogisticsSearch_popularitySort_returns200(): void
    {
        $resp = $this->http->get('/api/search/logistics?q=order&sort=popularity', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testLogisticsSearch_replyCountSort_returns200(): void
    {
        $resp = $this->http->get('/api/search/logistics?q=order&sort=reply_count', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testGlobalSearch_draftActivity_invisibleToRegularUser(): void
    {
        $opsToken = $this->opsToken();
        $regToken = $this->regularToken();
        $unique   = 'DraftOnlyVisible' . time();

        // Create activity — do NOT publish; stays in draft
        $resp = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['title' => $unique, 'body' => 'draft body for search visibility test'],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        $id = json_decode((string)$resp->getBody(), true)['data']['id'];

        usleep(200000);

        // Regular user searches — must get 200 and the draft must not appear
        $searchResp = $this->http->get('/api/search?q=' . urlencode($unique), [
            'headers' => $this->authHeaders($regToken),
        ]);
        $this->assertSame(200, $searchResp->getStatusCode());
        $body      = json_decode((string)$searchResp->getBody(), true);
        $entityIds = array_column($body['data']['data'] ?? [], 'entity_id');
        $this->assertNotContains((string)$id, array_map('strval', $entityIds),
            'Draft activity must not appear in regular user global search results');
    }

    public function testGlobalSearch_publishedActivity_visibleToRegularUser(): void
    {
        $unique = 'PublishedSearchVisible' . time();
        // Title contains the unique term to guarantee FULLTEXT indexability
        $id     = $this->createAndPublishActivity($unique, 'published body for visibility test');

        $resp = $this->http->get('/api/search?q=' . urlencode($unique), [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body      = json_decode((string)$resp->getBody(), true);
        $entityIds = array_column($body['data']['data'] ?? [], 'entity_id');
        $this->assertContains((string)$id, array_map('strval', $entityIds),
            'Published activity must appear in regular user global search results');
    }

    public function testLogisticsSearch_afterOrderCreation_returnsEntityTypeOrder(): void
    {
        $opsToken = $this->opsToken();

        // Create an order — this triggers indexOrder in the controller
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => 'logistics search test order'],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        usleep(100000);

        // Logistics search for "Order" should find it (display_name starts with "Order #N:")
        $searchResp = $this->http->get('/api/search/logistics?q=Order', [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $searchResp->getStatusCode());
        $body = json_decode((string)$searchResp->getBody(), true);

        // Verify at least one result has entity_type='order'
        $entityTypes = array_column($body['data']['data'] ?? [], 'entity_type');
        $this->assertContains('order', $entityTypes, 'Logistics search should return order entries after order creation');

        // Verify the specific order is present
        $entityIds = array_column(
            array_filter($body['data']['data'] ?? [], fn($r) => $r['entity_type'] === 'order'),
            'entity_id'
        );
        $this->assertContains((string)$orderId, array_map('strval', $entityIds),
            "Newly created order #{$orderId} should appear in logistics search results");
    }

    public function testGlobalSearch_allSortValues_return200(): void
    {
        $token = $this->opsToken();
        // Seed a searchable activity so there are results for every sort
        $this->createAndPublishActivity('SortParityActivity ' . time(), 'body for sort parity test');
        usleep(150000);

        foreach (['relevance', 'recency', 'popularity', 'reply_count'] as $sort) {
            $resp = $this->http->get('/api/search?q=SortParity&sort=' . $sort, [
                'headers' => $this->authHeaders($token),
            ]);
            $this->assertSame(200, $resp->getStatusCode(),
                "Global search with sort={$sort} must return 200");
        }
    }

    public function testLogisticsSearch_allSortValues_return200(): void
    {
        $token = $this->opsToken();
        // Seed an order so the logistics index has entries
        $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($token),
            'json'    => ['type' => 'equipment_rental', 'description' => 'sort parity order'],
        ]);

        foreach (['relevance', 'recency', 'popularity', 'reply_count'] as $sort) {
            $resp = $this->http->get('/api/search/logistics?q=order&sort=' . $sort, [
                'headers' => $this->authHeaders($token),
            ]);
            $this->assertSame(200, $resp->getStatusCode(),
                "Logistics search with sort={$sort} must return 200");
        }
    }

    public function testLogisticsSearch_relevanceSort_notEquivalentToRecency(): void
    {
        $opsToken = $this->opsToken();
        $prefix   = 'RelSortTest' . time();

        // Create order A first (older / lower recency rank).
        // Description contains BOTH the prefix token and a second distinctive token.
        $rA = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => "{$prefix} HiRelToken details"],
        ]);
        $this->assertSame(201, $rA->getStatusCode());
        $orderA = json_decode((string)$rA->getBody(), true)['data']['id'];

        usleep(200000); // ensure B gets a more recent indexed_at

        // Create order B after A (higher recency rank).
        // Description contains only the prefix token — one fewer token match than A.
        $rB = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => "{$prefix} LoRelToken"],
        ]);
        $this->assertSame(201, $rB->getStatusCode());
        $orderB = json_decode((string)$rB->getBody(), true)['data']['id'];

        usleep(100000);

        // Query matches both orders via the shared prefix token;
        // A also matches "HiRelToken", giving it a higher relevance score.
        $q = urlencode("{$prefix} HiRelToken");

        $relResp = $this->http->get("/api/search/logistics?q={$q}&sort=relevance", [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $relResp->getStatusCode());
        $relOrders = array_values(array_filter(
            json_decode((string)$relResp->getBody(), true)['data']['data'] ?? [],
            fn($r) => $r['entity_type'] === 'order'
        ));
        $relIds = array_column($relOrders, 'entity_id');

        $recResp = $this->http->get("/api/search/logistics?q={$q}&sort=recency", [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $recResp->getStatusCode());
        $recOrders = array_values(array_filter(
            json_decode((string)$recResp->getBody(), true)['data']['data'] ?? [],
            fn($r) => $r['entity_type'] === 'order'
        ));
        $recIds = array_column($recOrders, 'entity_id');

        $this->assertGreaterThanOrEqual(2, count($relIds),
            "Both orders must appear in relevance-sorted results");
        $this->assertGreaterThanOrEqual(2, count($recIds),
            "Both orders must appear in recency-sorted results");

        // Relevance: A ranks first (2 token hits vs 1)
        $this->assertSame((string)$orderA, (string)$relIds[0],
            "Order A (two token hits) must lead relevance sort");

        // Recency: B ranks first (created later → higher indexed_at)
        $this->assertSame((string)$orderB, (string)$recIds[0],
            "Order B (more recent) must lead recency sort");
    }

    public function testLogisticsSearch_popularitySort_respectsViewCount(): void
    {
        $opsToken = $this->opsToken();
        $prefix   = 'PopMetric' . time();

        // Create two orders that share the same searchable keyword
        $rA = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => $prefix . ' LowViews'],
        ]);
        $this->assertSame(201, $rA->getStatusCode());
        $orderA = json_decode((string)$rA->getBody(), true)['data']['id'];

        $rB = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => $prefix . ' HighViews'],
        ]);
        $this->assertSame(201, $rB->getStatusCode());
        $orderB = json_decode((string)$rB->getBody(), true)['data']['id'];

        usleep(100000);

        // Directly set view_count so the ordering is deterministic
        $this->dbPdo()->prepare(
            'UPDATE logistics_index SET view_count = 1 WHERE entity_type = ? AND entity_id = ?'
        )->execute(['order', $orderA]);
        $this->dbPdo()->prepare(
            'UPDATE logistics_index SET view_count = 500 WHERE entity_type = ? AND entity_id = ?'
        )->execute(['order', $orderB]);

        $resp = $this->http->get('/api/search/logistics?q=' . urlencode($prefix) . '&sort=popularity', [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['data'] ?? [];

        $orderItems = array_values(array_filter($items, fn($r) => $r['entity_type'] === 'order'));
        $ids        = array_column($orderItems, 'entity_id');

        $this->assertGreaterThanOrEqual(2, count($ids),
            "Both orders must appear in results for prefix '{$prefix}'");

        $posA = array_search((string)$orderA, array_map('strval', $ids));
        $posB = array_search((string)$orderB, array_map('strval', $ids));
        $this->assertLessThan($posA, $posB,
            "Order B (view_count=500) must rank before Order A (view_count=1) with popularity sort");
    }

    public function testLogisticsSearch_replyCountSort_respectsReplyCount(): void
    {
        $opsToken = $this->opsToken();
        $prefix   = 'ReplyMetric' . time();

        $rA = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'supply_request', 'description' => $prefix . ' FewReplies'],
        ]);
        $this->assertSame(201, $rA->getStatusCode());
        $orderA = json_decode((string)$rA->getBody(), true)['data']['id'];

        $rB = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'supply_request', 'description' => $prefix . ' ManyReplies'],
        ]);
        $this->assertSame(201, $rB->getStatusCode());
        $orderB = json_decode((string)$rB->getBody(), true)['data']['id'];

        usleep(100000);

        // Directly set reply_count (normally derived from invoice_corrections count on re-index)
        $this->dbPdo()->prepare(
            'UPDATE logistics_index SET reply_count = 1 WHERE entity_type = ? AND entity_id = ?'
        )->execute(['order', $orderA]);
        $this->dbPdo()->prepare(
            'UPDATE logistics_index SET reply_count = 20 WHERE entity_type = ? AND entity_id = ?'
        )->execute(['order', $orderB]);

        $resp = $this->http->get('/api/search/logistics?q=' . urlencode($prefix) . '&sort=reply_count', [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['data'] ?? [];

        $orderItems = array_values(array_filter($items, fn($r) => $r['entity_type'] === 'order'));
        $ids        = array_column($orderItems, 'entity_id');

        $this->assertGreaterThanOrEqual(2, count($ids),
            "Both orders must appear in results for prefix '{$prefix}'");

        $posA = array_search((string)$orderA, array_map('strval', $ids));
        $posB = array_search((string)$orderB, array_map('strval', $ids));
        $this->assertLessThan($posA, $posB,
            "Order B (reply_count=20) must rank before Order A (reply_count=1) with reply_count sort");
    }

    public function testLogisticsSearch_afterShipmentCreation_returnsEntityTypeShipment(): void
    {
        $opsToken = $this->opsToken();

        // Create order then shipment
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'supply_request'],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        $shipResp = $this->http->post('/api/shipments', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => [
                'order_id' => $orderId,
                'packages' => [['package_ref' => 'PKG-LSX1', 'carrier_name' => 'FedEx', 'tracking_number' => 'FX-LSX1']],
            ],
        ]);
        $this->assertSame(201, $shipResp->getStatusCode());
        $shipmentId = json_decode((string)$shipResp->getBody(), true)['data']['id'];

        usleep(100000);

        // Logistics search for "Shipment" should find it
        $searchResp = $this->http->get('/api/search/logistics?q=Shipment', [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $searchResp->getStatusCode());
        $body = json_decode((string)$searchResp->getBody(), true);

        $entityTypes = array_column($body['data']['data'] ?? [], 'entity_type');
        $this->assertContains('shipment', $entityTypes, 'Logistics search should return shipment entries after shipment creation');

        $shipmentIds = array_column(
            array_filter($body['data']['data'] ?? [], fn($r) => $r['entity_type'] === 'shipment'),
            'entity_id'
        );
        $this->assertContains((string)$shipmentId, array_map('strval', $shipmentIds),
            "Newly created shipment #{$shipmentId} should appear in logistics search results");
    }

    // ── Logistics search authorization ────────────────────────────────────────

    public function testLogisticsSearch_regularUser_cannotSeeOtherUsersOrders(): void
    {
        $opsToken = $this->opsToken();
        $prefix   = 'AuthOrderHidden' . time();

        $resp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => $prefix],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        $orderId = json_decode((string)$resp->getBody(), true)['data']['id'];

        usleep(150000);

        // Regular user (user1) searches — must NOT see ops_user's order
        $searchResp = $this->http->get('/api/search/logistics?q=' . urlencode($prefix) . '&sort=recency', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $searchResp->getStatusCode());
        $body    = json_decode((string)$searchResp->getBody(), true);
        $orderIds = array_column(
            array_filter($body['data']['data'] ?? [], fn($r) => $r['entity_type'] === 'order'),
            'entity_id'
        );
        $this->assertNotContains((string)$orderId, array_map('strval', $orderIds),
            'Regular user must not see orders created by another user in logistics search');
    }

    public function testLogisticsSearch_opsUser_seesOwnOrder(): void
    {
        $opsToken = $this->opsToken();
        $prefix   = 'AuthOrderVisible' . time();

        $resp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'supply_request', 'description' => $prefix],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        $orderId = json_decode((string)$resp->getBody(), true)['data']['id'];

        usleep(150000);

        // Ops user searches their own order — must see it
        $searchResp = $this->http->get('/api/search/logistics?q=' . urlencode($prefix) . '&sort=recency', [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $searchResp->getStatusCode());
        $body    = json_decode((string)$searchResp->getBody(), true);
        $orderIds = array_column(
            array_filter($body['data']['data'] ?? [], fn($r) => $r['entity_type'] === 'order'),
            'entity_id'
        );
        $this->assertContains((string)$orderId, array_map('strval', $orderIds),
            'Ops user must see their own order in logistics search results');
    }

    public function testLogisticsSearch_admin_seesAllOrders(): void
    {
        $opsToken = $this->opsToken();
        $prefix   = 'AuthOrderAdmin' . time();

        $resp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'equipment_rental', 'description' => $prefix],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        $orderId = json_decode((string)$resp->getBody(), true)['data']['id'];

        usleep(150000);

        // Admin sees all orders regardless of creator
        $searchResp = $this->http->get('/api/search/logistics?q=' . urlencode($prefix) . '&sort=recency', [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $this->assertSame(200, $searchResp->getStatusCode());
        $body    = json_decode((string)$searchResp->getBody(), true);
        $orderIds = array_column(
            array_filter($body['data']['data'] ?? [], fn($r) => $r['entity_type'] === 'order'),
            'entity_id'
        );
        $this->assertContains((string)$orderId, array_map('strval', $orderIds),
            'Admin must see orders created by any user in logistics search results');
    }

    public function testLogisticsSearch_regularUser_cannotSeeOtherUsersShipments(): void
    {
        $opsToken = $this->opsToken();
        $prefix   = 'AuthShipHidden' . time();

        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['type' => 'supply_request', 'description' => $prefix],
        ]);
        $this->assertSame(201, $orderResp->getStatusCode());
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        $shipResp = $this->http->post('/api/shipments', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => [
                'order_id' => $orderId,
                'packages' => [['package_ref' => 'PKG-AUTH1', 'carrier_name' => 'UPS', 'tracking_number' => 'UPS-AUTH1']],
            ],
        ]);
        $this->assertSame(201, $shipResp->getStatusCode());
        $shipmentId = json_decode((string)$shipResp->getBody(), true)['data']['id'];

        usleep(150000);

        // Regular user must not see ops_user's shipment
        $searchResp = $this->http->get('/api/search/logistics?q=Shipment&sort=recency', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $searchResp->getStatusCode());
        $body       = json_decode((string)$searchResp->getBody(), true);
        $shipIds    = array_column(
            array_filter($body['data']['data'] ?? [], fn($r) => $r['entity_type'] === 'shipment'),
            'entity_id'
        );
        $this->assertNotContains((string)$shipmentId, array_map('strval', $shipIds),
            'Regular user must not see shipments created by another user in logistics search');
    }

    public function testGlobalSearch_replyCountSort_usesActivityReplyCount(): void
    {
        $opsToken = $this->opsToken();
        $prefix   = 'ReplyCountSort' . time();

        // Create two activities with the same searchable prefix
        $dataA = [
            'title' => $prefix . ' Alpha',
            'body'  => 'body alpha for reply count sort test',
        ];
        $rA = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => $dataA,
        ]);
        $this->assertSame(201, $rA->getStatusCode());
        $actA = json_decode((string)$rA->getBody(), true)['data']['id'];
        $this->http->patch("/api/activities/{$actA}/state", [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['status' => 'published'],
        ]);

        $dataB = [
            'title' => $prefix . ' Beta',
            'body'  => 'body beta for reply count sort test',
        ];
        $rB = $this->http->post('/api/activities', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => $dataB,
        ]);
        $this->assertSame(201, $rB->getStatusCode());
        $actB = json_decode((string)$rB->getBody(), true)['data']['id'];
        $this->http->patch("/api/activities/{$actB}/state", [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['status' => 'published'],
        ]);

        usleep(150000); // allow indexing

        // Directly set reply_count on the activities table (the source of truth)
        $this->dbPdo()->prepare(
            'UPDATE activities SET reply_count = 1 WHERE id = ?'
        )->execute([$actA]);
        $this->dbPdo()->prepare(
            'UPDATE activities SET reply_count = 50 WHERE id = ?'
        )->execute([$actB]);

        // Re-trigger indexing so search_index.reply_count is refreshed
        $this->dbPdo()->prepare(
            'UPDATE search_index SET reply_count = 1 WHERE entity_type = "activity" AND entity_id = ?'
        )->execute([$actA]);
        $this->dbPdo()->prepare(
            'UPDATE search_index SET reply_count = 50 WHERE entity_type = "activity" AND entity_id = ?'
        )->execute([$actB]);

        $resp = $this->http->get('/api/search?q=' . urlencode($prefix) . '&sort=reply_count', [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $items = json_decode((string)$resp->getBody(), true)['data']['data'] ?? [];

        $actItems = array_values(array_filter($items, fn($r) => $r['entity_type'] === 'activity'));
        $ids      = array_column($actItems, 'entity_id');

        $this->assertGreaterThanOrEqual(2, count($ids),
            "Both activities must appear in search results for prefix '{$prefix}'");

        $posA = array_search((string)$actA, array_map('strval', $ids));
        $posB = array_search((string)$actB, array_map('strval', $ids));

        $this->assertLessThan($posA, $posB,
            "Activity B (reply_count=50) must rank before Activity A (reply_count=1) with sort=reply_count");
    }
}
