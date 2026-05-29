<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

class FulfillmentTest extends TestCase
{
    private function createOrderAndShipment(): array
    {
        $orderResp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($this->opsToken()),
            'json'    => ['type' => 'equipment_rental'],
        ]);
        $orderId = json_decode((string)$orderResp->getBody(), true)['data']['id'];

        $shipResp = $this->http->post('/api/shipments', [
            'headers' => $this->authHeaders($this->opsToken()),
            'json'    => [
                'order_id' => $orderId,
                'packages' => [
                    ['package_ref' => 'PKG-A', 'carrier_name' => 'FastShip', 'tracking_number' => 'FS123'],
                    ['package_ref' => 'PKG-B', 'carrier_name' => 'FastShip', 'tracking_number' => 'FS456'],
                ],
            ],
        ]);
        $this->assertSame(201, $shipResp->getStatusCode());
        return json_decode((string)$shipResp->getBody(), true)['data'];
    }

    public function testCreateShipment_returns201WithPackages(): void
    {
        $shipment = $this->createOrderAndShipment();
        $this->assertArrayHasKey('id', $shipment);
        $this->assertCount(2, $shipment['packages'] ?? []);
    }

    public function testAddScanEvent_returns201(): void
    {
        $shipment = $this->createOrderAndShipment();
        $resp = $this->http->post("/api/shipments/{$shipment['id']}/events", [
            'headers' => $this->authHeaders($this->opsToken()),
            'json'    => ['event_type' => 'in_transit', 'location' => 'Warehouse A'],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
    }

    public function testConfirmDelivery_returns200(): void
    {
        $shipment = $this->createOrderAndShipment();
        $resp = $this->http->patch("/api/shipments/{$shipment['id']}/deliver", [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame('delivered', $body['data']['status']);
    }

    public function testGetSubscriptions_returns200(): void
    {
        $resp = $this->http->get('/api/subscriptions', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('notify_arrival', $body['data']);
    }

    public function testUpdateSubscription_persistsPreferences(): void
    {
        $token = $this->regularToken();
        $resp = $this->http->put('/api/subscriptions', [
            'headers' => $this->authHeaders($token),
            'json'    => ['notify_arrival' => false, 'notify_exception' => true],
        ]);
        $this->assertSame(200, $resp->getStatusCode());

        $check = $this->http->get('/api/subscriptions', ['headers' => $this->authHeaders($token)]);
        $data  = json_decode((string)$check->getBody(), true)['data'];
        $this->assertSame(0, (int)$data['notify_arrival']);
        $this->assertSame(1, (int)$data['notify_exception']);
    }

    public function testCreateShipment_unauthenticated_returns401(): void
    {
        $resp = $this->http->post('/api/shipments', ['json' => ['order_id' => 1, 'packages' => []]]);
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testAddEvent_regularUser_returns403(): void
    {
        $shipment = $this->createOrderAndShipment();
        $resp = $this->http->post("/api/shipments/{$shipment['id']}/events", [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['event_type' => 'in_transit'],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testConfirmDelivery_regularUser_returns403(): void
    {
        $shipment = $this->createOrderAndShipment();
        $resp = $this->http->patch("/api/shipments/{$shipment['id']}/deliver", [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testRecordException_regularUser_returns403(): void
    {
        $shipment = $this->createOrderAndShipment();
        $resp = $this->http->post("/api/shipments/{$shipment['id']}/exceptions", [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['reason' => 'damaged'],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testAddEvent_opsOwner_returns201(): void
    {
        $shipment = $this->createOrderAndShipment();
        $resp = $this->http->post("/api/shipments/{$shipment['id']}/events", [
            'headers' => $this->authHeaders($this->opsToken()),
            'json'    => ['event_type' => 'dispatched', 'location' => 'Gate 1'],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
    }

    public function testListShipments_authenticated_returns200WithList(): void
    {
        $this->createOrderAndShipment();
        $resp = $this->http->get('/api/shipments', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertIsArray($body['data']['data'] ?? $body['data'],
            'Shipment list response must be an array');
    }

    public function testListShipments_unauthenticated_returns401(): void
    {
        $resp = $this->http->get('/api/shipments');
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testGetShipment_existingId_returns200WithMatchingId(): void
    {
        $shipment   = $this->createOrderAndShipment();
        $shipmentId = $shipment['id'];

        $resp = $this->http->get("/api/shipments/{$shipmentId}", [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame($shipmentId, $body['data']['id'],
            'Returned shipment id must match the requested id');
    }

    public function testGetShipment_missingId_returns404(): void
    {
        $resp = $this->http->get('/api/shipments/999999999', [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testConfirmDelivery_createsNotification_withValidRecipient(): void
    {
        $shipment   = $this->createOrderAndShipment();
        $shipmentId = $shipment['id'];

        $pdo = $this->dbPdo();
        $pdo->prepare("DELETE FROM notifications WHERE entity_type = 'shipment' AND entity_id = ?")
            ->execute([$shipmentId]);

        $resp = $this->http->patch("/api/shipments/{$shipmentId}/deliver", [
            'headers' => $this->authHeaders($this->opsToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());

        $stmt = $pdo->prepare(
            "SELECT recipient_id FROM notifications WHERE entity_type = 'shipment' AND entity_id = ? LIMIT 1"
        );
        $stmt->execute([$shipmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row,
            "A notification row must be created in the notifications table after delivery confirmation");
        $this->assertGreaterThan(0, (int)$row['recipient_id'],
            "Notification recipient_id must be a valid user id — not 0 or null (FK guard)");
    }
}
