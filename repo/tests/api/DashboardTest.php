<?php
declare(strict_types=1);
namespace tests\api;

use tests\TestCase;

/**
 * True no-mock HTTP tests for dashboard CRUD, favorites, widget data, and exports.
 * All requests hit the real ThinkPHP server via Guzzle.
 */
class DashboardTest extends TestCase
{
    private function sampleLayout(): array
    {
        return [
            ['widget_type' => 'activity_status', 'position' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 4]],
            ['widget_type' => 'order_pipeline',  'position' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 4]],
        ];
    }

    // ---------------------------------------------------------------
    // CRUD
    // ---------------------------------------------------------------

    public function testCreateDashboard_returns201(): void
    {
        $res = $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'Test Board', 'layout_json' => $this->sampleLayout()],
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $body = json_decode((string)$res->getBody(), true);
        $this->assertSame(0, $body['code']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    public function testCreateDashboard_missingLayoutJson_returns422(): void
    {
        $res = $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'Bad Board'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testCreateDashboard_regularUser_returns403(): void
    {
        $res = $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->regularToken()],
            'json'    => ['name' => 'Should Fail', 'layout_json' => $this->sampleLayout()],
        ]);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testListDashboards_returns200WithArray(): void
    {
        // Ensure at least one exists
        $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'List Board', 'layout_json' => $this->sampleLayout()],
        ]);

        $res = $this->http->get('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode((string)$res->getBody(), true);
        $this->assertIsArray($body['data']);
    }

    public function testUpdateDashboard_returnsUpdatedName(): void
    {
        $create = $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'Original', 'layout_json' => $this->sampleLayout()],
        ]);
        $id = json_decode((string)$create->getBody(), true)['data']['id'];

        $res = $this->http->put("/api/dashboards/{$id}", [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'Updated', 'layout_json' => $this->sampleLayout()],
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode((string)$res->getBody(), true);
        $this->assertSame('Updated', $body['data']['name']);
    }

    public function testDeleteDashboard_subsequentGetReturns404(): void
    {
        $create = $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'To Delete', 'layout_json' => $this->sampleLayout()],
        ]);
        $id = json_decode((string)$create->getBody(), true)['data']['id'];

        $del = $this->http->delete("/api/dashboards/{$id}", [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
        ]);
        $this->assertSame(200, $del->getStatusCode());

        $check = $this->http->get("/api/dashboards/{$id}", [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
        ]);
        $this->assertSame(404, $check->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Favorites
    // ---------------------------------------------------------------

    public function testFavoriteDashboard_returns201WithMessage(): void
    {
        $create = $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'Fav Board', 'layout_json' => $this->sampleLayout()],
        ]);
        $id = json_decode((string)$create->getBody(), true)['data']['id'];

        $res = $this->http->post("/api/dashboards/{$id}/favorite", [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $body = json_decode((string)$res->getBody(), true);
        $this->assertStringContainsString('Favorited', $body['msg']);
    }

    public function testUnfavoriteDashboard_returns200(): void
    {
        $create = $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'Unfav Board', 'layout_json' => $this->sampleLayout()],
        ]);
        $id = json_decode((string)$create->getBody(), true)['data']['id'];

        // Favorite first
        $this->http->post("/api/dashboards/{$id}/favorite", [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
        ]);

        $res = $this->http->delete("/api/dashboards/{$id}/favorite", [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
        ]);
        $this->assertSame(200, $res->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Widget data
    // ---------------------------------------------------------------

    public function testWidgetData_activityStatus_returns200(): void
    {
        $res = $this->http->get('/api/widgets/data?widget_type=activity_status', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode((string)$res->getBody(), true);
        $this->assertSame(0, $body['code']);
        $this->assertIsArray($body['data']);
    }

    public function testWidgetData_invalidType_returns422(): void
    {
        $res = $this->http->get('/api/widgets/data?widget_type=invalid_widget', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Export
    // ---------------------------------------------------------------

    public function testExportDashboard_pdf_returns201WithFilePath(): void
    {
        $create = $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'Export PDF', 'layout_json' => $this->sampleLayout()],
        ]);
        $id = json_decode((string)$create->getBody(), true)['data']['id'];

        $res = $this->http->post("/api/dashboards/{$id}/export", [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['format' => 'pdf'],
        ]);
        $this->assertSame(201, $res->getStatusCode());
        $body = json_decode((string)$res->getBody(), true);
        $this->assertStringContainsString('.pdf', $body['data']['file_path']);
    }

    public function testExportDashboard_invalidFormat_returns422(): void
    {
        $create = $this->http->post('/api/dashboards', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['name' => 'Bad Export', 'layout_json' => $this->sampleLayout()],
        ]);
        $id = json_decode((string)$create->getBody(), true)['data']['id'];

        $res = $this->http->post("/api/dashboards/{$id}/export", [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
            'json'    => ['format' => 'doc'],
        ]);
        $this->assertSame(422, $res->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Sensitive field masking
    // ---------------------------------------------------------------

    public function testSensitiveFields_regularUser_returns403(): void
    {
        $res = $this->http->get('/api/users/1/sensitive', [
            'headers' => ['Authorization' => 'Bearer ' . $this->regularToken()],
        ]);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function testSensitiveFields_admin_returns200WithPassengerId(): void
    {
        $res = $this->http->get('/api/users/1/sensitive', [
            'headers' => ['Authorization' => 'Bearer ' . $this->adminToken()],
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode((string)$res->getBody(), true);
        $this->assertArrayHasKey('passenger_id', $body['data']);
    }
}
