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
}
