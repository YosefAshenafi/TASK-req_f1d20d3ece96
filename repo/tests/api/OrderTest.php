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

    private function advanceOrderToClosed(string $token, int $orderId): void
    {
        foreach (['pending_payment', 'paid', 'ticketing', 'ticketed', 'closed'] as $status) {
            $resp = $this->http->patch("/api/orders/{$orderId}/state", [
                'headers' => $this->authHeaders($token),
                'json'    => ['status' => $status],
            ]);
            $this->assertSame(200, $resp->getStatusCode(),
                "Failed to advance order #{$orderId} to '{$status}': " . $resp->getBody());
        }
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
        $this->createOrder($this->opsToken());
        $resp = $this->http->get('/api/orders', [
            'headers' => $this->authHeaders($this->regularToken()),
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        foreach ($body['data']['data'] ?? [] as $order) {
            $this->assertNotSame('ops_user', $order['creator']['username'] ?? '');
        }
    }

    public function testTransition_nonOwnerRegularUser_returns403(): void
    {
        $data = $this->createOrder($this->opsToken());
        $id   = $data['id'];

        $resp = $this->http->patch("/api/orders/{$id}/state", [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['status' => 'pending_payment'],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testInvoiceCorrection_nonOwnerRegularUser_returns403(): void
    {
        $data = $this->createOrder($this->opsToken());
        $id   = $data['id'];

        $resp = $this->http->post("/api/orders/{$id}/invoice-corrections", [
            'headers' => $this->authHeaders($this->regularToken()),
            'json'    => ['invoice_address' => 'New Address', 'invoice_contact' => 'Contact'],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testReviewCorrection_asAdmin_returns403(): void
    {
        $opsToken = $this->opsToken();
        $data = $this->createOrder($opsToken, ['invoice_address' => '123 Main St']);
        $id   = $data['id'];
        $this->advanceOrderToClosed($opsToken, $id);

        $corrResp = $this->http->post("/api/orders/{$id}/invoice-corrections", [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['invoice_address' => '456 New Ave'],
        ]);
        $this->assertSame(201, $corrResp->getStatusCode());
        $corrId = json_decode((string)$corrResp->getBody(), true)['data']['correction_id'];

        $resp = $this->http->patch("/api/invoice-corrections/{$corrId}/review", [
            'headers' => $this->authHeaders($this->adminToken()),
            'json'    => ['decision' => 'approved', 'decision_notes' => 'Looks good'],
        ]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testReviewCorrection_asReviewer_returns200(): void
    {
        $opsToken = $this->opsToken();
        $data = $this->createOrder($opsToken, ['invoice_address' => '789 Oak Rd']);
        $id   = $data['id'];
        $this->advanceOrderToClosed($opsToken, $id);

        $corrResp = $this->http->post("/api/orders/{$id}/invoice-corrections", [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['invoice_address' => '999 New Road'],
        ]);
        $this->assertSame(201, $corrResp->getStatusCode());
        $corrId = json_decode((string)$corrResp->getBody(), true)['data']['correction_id'];

        $resp = $this->http->patch("/api/invoice-corrections/{$corrId}/review", [
            'headers' => $this->authHeaders($this->reviewerToken()),
            'json'    => ['decision' => 'approved', 'decision_notes' => 'Address verified'],
        ]);
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertSame('approved', $body['data']['status']);
    }

    public function testOrderShow_doesNotExposeRawEncryptedBlobs(): void
    {
        $opsToken = $this->opsToken();

        $resp = $this->http->post('/api/orders', [
            'headers' => $this->authHeaders($opsToken),
            'json'    => [
                'type'            => 'equipment_rental',
                'description'     => 'Encryption field test',
                'invoice_contact' => 'Sensitive Contact',
                'invoice_address' => 'Sensitive Address 42',
            ],
        ]);
        $this->assertSame(201, $resp->getStatusCode());
        $id = json_decode((string)$resp->getBody(), true)['data']['id'];

        // Non-admin (owner) — masked values, no raw _enc blobs
        $ownerResp = $this->http->get("/api/orders/{$id}", [
            'headers' => $this->authHeaders($opsToken),
        ]);
        $this->assertSame(200, $ownerResp->getStatusCode());
        $ownerData = json_decode((string)$ownerResp->getBody(), true)['data'];
        $this->assertArrayNotHasKey('invoice_contact_enc', $ownerData,
            '_enc column must not appear in response');
        $this->assertArrayNotHasKey('invoice_address_enc', $ownerData,
            '_enc column must not appear in response');
        $this->assertStringContainsString('***', $ownerData['invoice_contact'] ?? '***',
            'Non-admin must receive masked invoice_contact');

        // Admin — full decrypted values, still no _enc blobs
        $adminResp = $this->http->get("/api/orders/{$id}", [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $this->assertSame(200, $adminResp->getStatusCode());
        $adminData = json_decode((string)$adminResp->getBody(), true)['data'];
        $this->assertArrayNotHasKey('invoice_contact_enc', $adminData,
            '_enc column must not appear in response');
        $this->assertArrayNotHasKey('invoice_address_enc', $adminData,
            '_enc column must not appear in response');
        $this->assertSame('Sensitive Contact', $adminData['invoice_contact'],
            'Admin must receive decrypted invoice_contact');
        $this->assertSame('Sensitive Address 42', $adminData['invoice_address'],
            'Admin must receive decrypted invoice_address');
    }

    public function testCorrectionPayload_isEncryptedAtRest(): void
    {
        $opsToken      = $this->opsToken();
        $plainAddress  = 'PlaintextCheck Avenue 99';

        $data = $this->createOrder($opsToken);
        $id   = $data['id'];
        $this->advanceOrderToClosed($opsToken, $id);

        $corrResp = $this->http->post("/api/orders/{$id}/invoice-corrections", [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['invoice_address' => $plainAddress],
        ]);
        $this->assertSame(201, $corrResp->getStatusCode(),
            'Correction request must succeed on a closed order');
        $corrId = json_decode((string)$corrResp->getBody(), true)['data']['correction_id'];

        // Read field_patch directly from DB — must not contain plaintext
        $stmt = $this->dbPdo()->prepare(
            'SELECT field_patch FROM invoice_corrections WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$corrId]);
        $raw = $stmt->fetchColumn();
        $this->assertNotFalse($raw, 'Correction row must exist in invoice_corrections');

        $patch = json_decode($raw, true);
        $this->assertIsArray($patch, 'field_patch must be valid JSON');

        // Plaintext keys must not appear
        $this->assertArrayNotHasKey('invoice_address', $patch,
            'Plaintext invoice_address must not be stored in field_patch');
        $this->assertArrayNotHasKey('invoice_contact', $patch,
            'Plaintext invoice_contact must not be stored in field_patch');

        // Encrypted key must be present
        $this->assertArrayHasKey('invoice_address_enc', $patch,
            'Encrypted invoice_address_enc must be present in field_patch');

        // Encrypted value must differ from plaintext
        $this->assertNotSame($plainAddress, $patch['invoice_address_enc'],
            'Stored value must be ciphertext, not the original plaintext string');
    }

    public function testReviewCorrection_encryptedPatch_appliesCorrectly(): void
    {
        $opsToken      = $this->opsToken();
        $newAddress    = 'Corrected Street 77';

        $data = $this->createOrder($opsToken, ['invoice_address' => 'Original Street 1']);
        $id   = $data['id'];
        $this->advanceOrderToClosed($opsToken, $id);

        // Request correction with new address
        $corrResp = $this->http->post("/api/orders/{$id}/invoice-corrections", [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['invoice_address' => $newAddress],
        ]);
        $this->assertSame(201, $corrResp->getStatusCode());
        $corrId = json_decode((string)$corrResp->getBody(), true)['data']['correction_id'];

        // Reviewer approves the encrypted-patch correction
        $reviewResp = $this->http->patch("/api/invoice-corrections/{$corrId}/review", [
            'headers' => $this->authHeaders($this->reviewerToken()),
            'json'    => ['decision' => 'approved', 'decision_notes' => 'Verified new address'],
        ]);
        $this->assertSame(200, $reviewResp->getStatusCode());

        // Admin reads order — must see the corrected, decrypted address
        $adminResp = $this->http->get("/api/orders/{$id}", [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $this->assertSame(200, $adminResp->getStatusCode());
        $adminData = json_decode((string)$adminResp->getBody(), true)['data'];
        $this->assertSame($newAddress, $adminData['invoice_address'],
            'Admin must see corrected, decrypted address after reviewer approval');
    }

    // ── Correction scope enforcement ────────────────────────────────────────

    public function testRequestCorrection_invoiceContactRejected_returns422(): void
    {
        $opsToken = $this->opsToken();
        $data     = $this->createOrder($opsToken);
        $this->advanceOrderToClosed($opsToken, $data['id']);

        $resp = $this->http->post("/api/orders/{$data['id']}/invoice-corrections", [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['invoice_contact' => 'New Contact Person'],
        ]);
        $this->assertSame(422, $resp->getStatusCode(),
            'Closed-order correction must reject invoice_contact updates');
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertNotEmpty($body['msg'],
            'Response must include a descriptive error message');
    }

    public function testRequestCorrection_invoiceAddressOnly_returns201(): void
    {
        $opsToken = $this->opsToken();
        $data     = $this->createOrder($opsToken);
        $this->advanceOrderToClosed($opsToken, $data['id']);

        $resp = $this->http->post("/api/orders/{$data['id']}/invoice-corrections", [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['invoice_address' => '99 Correction Lane'],
        ]);
        $this->assertSame(201, $resp->getStatusCode(),
            'Closed-order correction must accept invoice_address');
        $body = json_decode((string)$resp->getBody(), true);
        $this->assertArrayHasKey('correction_id', $body['data']);
    }

    public function testReviewCorrection_approvalAppliesAddressNotContact(): void
    {
        $opsToken   = $this->opsToken();
        $newAddress = 'Approved Address 123';

        $data = $this->createOrder($opsToken, [
            'invoice_address' => 'Old Address 1',
            'invoice_contact' => 'Old Contact',
        ]);
        $id = $data['id'];
        $this->advanceOrderToClosed($opsToken, $id);

        $corrResp = $this->http->post("/api/orders/{$id}/invoice-corrections", [
            'headers' => $this->authHeaders($opsToken),
            'json'    => ['invoice_address' => $newAddress],
        ]);
        $this->assertSame(201, $corrResp->getStatusCode());
        $corrId = json_decode((string)$corrResp->getBody(), true)['data']['correction_id'];

        $reviewResp = $this->http->patch("/api/invoice-corrections/{$corrId}/review", [
            'headers' => $this->authHeaders($this->reviewerToken()),
            'json'    => ['decision' => 'approved', 'decision_notes' => 'Address verified'],
        ]);
        $this->assertSame(200, $reviewResp->getStatusCode());

        // Admin sees corrected address and unchanged contact
        $adminResp = $this->http->get("/api/orders/{$id}", [
            'headers' => $this->authHeaders($this->adminToken()),
        ]);
        $adminData = json_decode((string)$adminResp->getBody(), true)['data'];
        $this->assertSame($newAddress, $adminData['invoice_address'],
            'Approved correction must apply the new invoice_address');
        $this->assertSame('Old Contact', $adminData['invoice_contact'],
            'Correction approval must not modify invoice_contact');
    }
}
