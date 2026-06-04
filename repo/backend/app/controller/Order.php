<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Log;
use think\facade\Db;
use app\model\Order as OrderModel;
use app\service\OrderService;
use app\service\SearchIndexService;
use app\service\EncryptionService;
use app\exception\ForbiddenException;
use app\exception\NotFoundException;

class Order
{
    private OrderService $service;
    public function __construct() { $this->service = new OrderService(); }

    public function index(Request $request)
    {
        $page    = max(1, (int)$request->get('page', 1));
        $perPage = min(50, (int)$request->get('per_page', 20));
        $role    = $request->user_role;

        $query = OrderModel::with(['creator']);
        if (!in_array($role, ['admin'], true)) {
            $query->where('created_by', $request->user_id);
        }

        return json(['code' => 200, 'msg' => 'ok', 'data' => $query->paginate(['list_rows' => $perPage, 'page' => $page])->toArray()]);
    }

    public function show(Request $request, int $id)
    {
        $order = OrderModel::with(['creator', 'shipments'])->find($id);
        if (!$order) throw new NotFoundException('Order not found');

        if ($request->user_role !== 'admin' && $order->created_by !== $request->user_id) {
            throw new ForbiddenException('Access denied');
        }

        $data = $order->toArray();

        // Decrypt invoice_contact_enc and invoice_address_enc for response;
        // expose as invoice_contact / invoice_address (never expose raw encrypted blobs).
        unset($data['invoice_contact_enc'], $data['invoice_address_enc']);

        $isAdmin = $request->user_role === 'admin';

        foreach (['invoice_contact' => 'invoice_contact_enc', 'invoice_address' => 'invoice_address_enc'] as $plain => $enc) {
            $ciphertext = $order->getData($enc);
            if ($ciphertext === null || $ciphertext === '') {
                $data[$plain] = null;
                continue;
            }
            try {
                $decrypted    = EncryptionService::decrypt($ciphertext);
                $data[$plain] = $isAdmin ? $decrypted : EncryptionService::mask($decrypted);
            } catch (\Throwable $e) {
                $data[$plain] = $isAdmin ? null : '***';
            }
        }

        return json(['code' => 200, 'msg' => 'ok', 'data' => $data]);
    }

    public function create(Request $request)
    {
        if (!in_array($request->user_role, ['admin', 'ops_staff'], true)) {
            throw new ForbiddenException('Operations Staff or Administrator access required');
        }

        $data = $request->post();
        if (empty($data['type'])) {
            return json(['code' => 422, 'msg' => 'Order type is required', 'errors' => ['type' => 'required']], 422);
        }

        // activity_id is optional; when provided it must reference an existing
        // activity (FK fk_orders_activity). Validate here so a bad reference
        // returns a clean 422 instead of an unhandled SQL integrity violation.
        $activityId = $data['activity_id'] ?? null;
        if ($activityId === '' || $activityId === 0 || $activityId === '0') {
            $activityId = null;
        }
        if ($activityId !== null) {
            $activityId = (int)$activityId;
            if (!\app\model\Activity::where('id', $activityId)->find()) {
                return json(['code' => 422, 'msg' => 'Activity not found for the given activity_id', 'errors' => ['activity_id' => 'not_found']], 422);
            }
        }

        $row = [
            'activity_id' => $activityId,
            'created_by'  => $request->user_id,
            'type'        => $data['type'],
            'description' => $data['description'] ?? null,
            'status'      => 'placed',
            'placed_at'   => date('Y-m-d H:i:s'),
        ];

        // Encrypt sensitive invoice fields — never persist plaintext
        if (!empty($data['invoice_contact'])) {
            $row['invoice_contact_enc'] = EncryptionService::encrypt((string)$data['invoice_contact']);
        }
        if (!empty($data['invoice_address'])) {
            $row['invoice_address_enc'] = EncryptionService::encrypt((string)$data['invoice_address']);
        }

        $order = OrderModel::create($row);
        // Set stable family_id: each order is its own family
        OrderModel::where('id', $order->id)->update(['family_id' => 'order:' . $order->id]);
        Log::info('order_created', ['id' => $order->id, 'by' => $request->user_id]);

        try { (new SearchIndexService())->indexOrder((int)$order->id); } catch (\Throwable $e) { Log::warning('order_index_failed', ['error' => $e->getMessage()]); }

        return json(['code' => 201, 'msg' => 'Order created', 'data' => ['id' => $order->id, 'status' => $order->status]], 201);
    }

    public function transition(Request $request, int $id)
    {
        $data   = $request->put() ?: $request->post();
        $target = $data['status'] ?? '';

        if (empty($target)) {
            return json(['code' => 422, 'msg' => 'status is required', 'errors' => ['status' => 'required']], 422);
        }

        $order = OrderModel::find($id);
        if (!$order) {
            throw new NotFoundException('Order not found');
        }
        if ($request->user_role !== 'admin' && $order->created_by !== $request->user_id) {
            throw new ForbiddenException('Access denied');
        }

        try {
            $order = $this->service->transition($id, $target, (int)$request->user_id, $request->user_role);
        } catch (\app\exception\OrderStateException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage(), 'errors' => []], 422);
        }

        try { (new SearchIndexService())->indexOrder($id); } catch (\Throwable $e) { Log::warning('order_index_failed', ['error' => $e->getMessage()]); }

        return json(['code' => 200, 'msg' => 'Order status updated', 'data' => ['status' => $order->status]]);
    }

    public function refund(Request $request, int $id)
    {
        $data  = $request->post();
        $order = $this->service->refund($id, (int)$request->user_id, $request->user_role, $data['reason'] ?? null);
        Log::info('order_refunded', ['id' => $id, 'by' => $request->user_id]);
        return json(['code' => 200, 'msg' => 'Refund processed', 'data' => ['status' => $order->status]]);
    }

    public function requestCorrection(Request $request, int $id)
    {
        $order = OrderModel::find($id);
        if (!$order) {
            throw new NotFoundException('Order not found');
        }
        if ($request->user_role !== 'admin' && $order->created_by !== $request->user_id) {
            throw new ForbiddenException('Access denied');
        }

        $data       = $request->post();
        $correction = $this->service->requestCorrection($id, $data, (int)$request->user_id);
        return json(['code' => 201, 'msg' => 'Correction requested', 'data' => ['correction_id' => $correction->id, 'status' => $correction->status]], 201);
    }

    public function reviewCorrection(Request $request, int $id)
    {
        // Only reviewer role may approve/reject invoice corrections
        if ($request->user_role !== 'reviewer') {
            throw new ForbiddenException('Reviewer access required');
        }

        $data     = $request->put() ?: $request->post();
        $decision = $data['decision'] ?? '';
        $notes    = $data['decision_notes'] ?? '';

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return json(['code' => 422, 'msg' => 'decision must be approved or rejected', 'errors' => []], 422);
        }
        if (empty(trim($notes))) {
            return json(['code' => 422, 'msg' => 'decision_notes are required', 'errors' => ['decision_notes' => 'required']], 422);
        }

        $correction = $this->service->reviewCorrection($id, $decision, $notes, (int)$request->user_id);
        return json(['code' => 200, 'msg' => 'Review submitted', 'data' => ['status' => $correction->status]]);
    }

    /**
     * DELETE /api/orders/{id} — Admin (any order) or Operations Staff (own orders).
     *
     * Hard delete with hierarchical cascade: removes the order together with its
     * shipments (and each shipment's packages and scan events), refunds, and
     * invoice corrections in a single transaction, then drops the order and its
     * shipments from the logistics index. Irreversible.
     */
    public function destroy(Request $request, int $id)
    {
        if (!in_array($request->user_role, ['admin', 'ops_staff'], true)) {
            throw new ForbiddenException('Operations Staff or Administrator access required');
        }

        $order = OrderModel::find($id);
        if (!$order) throw new NotFoundException('Order not found');
        if ($request->user_role !== 'admin' && $order->created_by !== $request->user_id) {
            throw new ForbiddenException('Access denied');
        }

        $index       = new SearchIndexService();
        $shipmentIds = Db::table('shipments')->where('order_id', $id)->column('id');

        $counts = Db::transaction(function () use ($id, $shipmentIds) {
            $c = [
                'shipments'           => count($shipmentIds),
                'packages'            => 0,
                'events'              => 0,
                'refunds'             => Db::table('order_refunds')->where('order_id', $id)->count(),
                'invoice_corrections' => Db::table('invoice_corrections')->where('order_id', $id)->count(),
            ];

            if (!empty($shipmentIds)) {
                $c['events']   = Db::table('shipment_events')->whereIn('shipment_id', $shipmentIds)->count();
                $c['packages'] = Db::table('shipment_packages')->whereIn('shipment_id', $shipmentIds)->count();
                Db::table('shipment_events')->whereIn('shipment_id', $shipmentIds)->delete();
                Db::table('shipment_packages')->whereIn('shipment_id', $shipmentIds)->delete();
                Db::table('shipments')->whereIn('id', $shipmentIds)->delete();
            }

            Db::table('order_refunds')->where('order_id', $id)->delete();
            Db::table('invoice_corrections')->where('order_id', $id)->delete();
            Db::table('orders')->where('id', $id)->delete();

            return $c;
        });

        foreach ($shipmentIds as $sid) { try { $index->deleteIndex('shipment', (int)$sid); } catch (\Throwable $e) {} }
        try { $index->deleteIndex('order', $id); } catch (\Throwable $e) {}

        try {
            Db::table('audit_log')->insert([
                'user_id'     => (int)$request->user_id,
                'action'      => 'delete',
                'entity_type' => 'order',
                'entity_id'   => $id,
                'payload'     => json_encode($counts, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) { Log::warning('audit_write_failed', ['entity' => 'order', 'id' => $id, 'error' => $e->getMessage()]); }

        Log::info('order_deleted', ['id' => $id, 'by' => $request->user_id, 'cascade' => $counts]);
        return json(['code' => 200, 'msg' => 'Order deleted', 'data' => ['id' => $id, 'cascade' => $counts]]);
    }
}
