<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

class OrderTest extends TestCase
{
    private function createOrder(string $token, array $override = []): array
    {
        $resp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($token),
            'json'    => array_merge(['type' => 'equipment_rental', 'description' => 'Test order'], $override),
        ]);
        $this->assertSame(201, $resp->getStatusCode(), 'Order creation failed: ' . $resp->getBody());
        return json_decode((string)$resp->getBody(), true)['data'];
    }

    public function testCreateOrder_asOps_returns201(): void
    {
        $data = $this->createOrder($this->opsToken());
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('placed', $data['status']);
    }

    public function testCreateOrder_asRegularUser_returns403(): void
    {
        $resp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['type' => 'equipment'],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testCreateOrder_missingType_returns422(): void
    {
        $resp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($this->opsToken()),
            'json'    => ['description' => 'missing type'],
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testGetOrder_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/orders/1');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testGetOrder_objectLevelAuthz_returns403(): void
    {
        $opsData = $this->createOrder($this->opsToken());
        $resp    = $this->http->get("/api/orders/{$opsData['id']}", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testTransition_validMove_returns200(): void
    {
        $data  = $this->createOrder($this->opsToken());
        $id    = $data['id'];
        $token = $this->opsToken();

        $resp = $this->http->patch("/api/orders/{$id}/state", [
            'headers' => $this->authHeaders($token),
            'json'    => ['status' => 'pending_payment'],
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame('pending_payment', $body['data']['status']);
    }

    public function testTransition_illegalMove_returns422(): void
    {
        $data = $this->createOrder($this->opsToken());
        $id   = $data['id'];

        $resp = $this->http->patch("/api/orders/{$id}/state", [
            'headers' => $this->authHeaders($this->opsToken()),
            'json'    => ['status' => 'ticketed'], // placed -> ticketed is illegal
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testRefund_asNonAdmin_returns403(): void
    {
        $data = $this->createOrder($this->opsToken());
        $resp = $this->http->post("/api/orders/{$data['id']}/refund", [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testRefund_onNonPaidOrder_returns422(): void
    {
        $data = $this->createOrder($this->opsToken());
        $resp = $this->http->post("/api/orders/{$data['id']}/refund", [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['reason' => 'test refund'],
        ]);
        $this->assertSame(422, $resp->getStatusCode());
    }

    public function testListOrders_scopedToUser(): void
    {
        // ops_user creates an order
        $this->createOrder($this->opsToken());
        // regular user should NOT see it
        $resp = $this->http->get('/api/orders', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        // Regular user has no orders — list should be empty (tenant isolation)
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        // All returned orders should belong to the regular user (user1)
        foreach ($body['data']['data'] ?? [] as $order) {
            $this->assertNotSame('ops_user', $order['creator']['username'] ?? '');
        }
    }
}
