<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Log;
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

        $row = [
            'activity_id' => $data['activity_id'] ?? null,
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
}
