<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Log;
use app\model\Shipment;
use app\service\FulfillmentService;
use app\exception\ForbiddenException;
use app\exception\NotFoundException;

class Fulfillment
{
    private FulfillmentService $service;
    public function __construct() { $this->service = new FulfillmentService(); }

    public function index(Request $request)
    {
        $query = Shipment::with(['packages']);
        if ($request->user_role !== 'admin') {
            $query->where('created_by', $request->user_id);
        }
        $data = $query->paginate(20);
        return json(['code' => 200, 'msg' => 'ok', 'data' => $data->toArray()]);
    }

    public function show(Request $request, int $id)
    {
        $s = Shipment::with(['packages', 'events'])->find($id);
        if (!$s) throw new NotFoundException('Shipment not found');
        if ($request->user_role !== 'admin' && $s->created_by !== $request->user_id) {
            throw new ForbiddenException('Access denied');
        }
        return json(['code' => 200, 'msg' => 'ok', 'data' => $s->toArray()]);
    }

    public function create(Request $request)
    {
        if (!in_array($request->user_role, ['admin', 'ops_staff'], true)) {
            throw new ForbiddenException('Operations Staff access required');
        }
        $data     = $request->post();
        $orderId  = (int)($data['order_id'] ?? 0);
        $packages = $data['packages'] ?? [];
        if (!$orderId) return json(['code' => 422, 'msg' => 'order_id required', 'errors' => []], 422);
        if (empty($packages)) return json(['code' => 422, 'msg' => 'At least one package required', 'errors' => []], 422);

        $shipment = $this->service->createShipment($orderId, $packages, (int)$request->user_id);
        return json(['code' => 201, 'msg' => 'Shipment created', 'data' => $shipment->toArray()], 201);
    }

    public function addEvent(Request $request, int $id)
    {
        $data = $request->post();
        $eventType = $data['event_type'] ?? '';
        $valid = ['dispatched', 'in_transit', 'delivered', 'exception'];
        if (!in_array($eventType, $valid, true)) {
            return json(['code' => 422, 'msg' => 'Invalid event_type', 'errors' => []], 422);
        }
        $event = $this->service->recordScanEvent($id, $eventType, $data['location'] ?? null, $data['note'] ?? null, (int)$request->user_id);
        return json(['code' => 201, 'msg' => 'Event recorded', 'data' => $event->toArray()], 201);
    }

    public function confirmDelivery(Request $request, int $id)
    {
        $shipment = $this->service->confirmDelivery($id, (int)$request->user_id);
        return json(['code' => 200, 'msg' => 'Delivery confirmed', 'data' => ['status' => $shipment->status]]);
    }

    public function recordException(Request $request, int $id)
    {
        $data  = $request->post();
        $event = $this->service->recordException($id, $data['note'] ?? '', (int)$request->user_id);
        return json(['code' => 201, 'msg' => 'Exception recorded', 'data' => $event->toArray()], 201);
    }

    public function getSubscription(Request $request)
    {
        $sub = \app\model\UserSubscription::where('user_id', $request->user_id)->find();
        $defaults = ['notify_arrival' => 1, 'notify_exception' => 1, 'notify_delay' => 1];
        return json(['code' => 200, 'msg' => 'ok', 'data' => $sub ? $sub->toArray() : $defaults]);
    }

    public function updateSubscription(Request $request)
    {
        $sub = $this->service->updateSubscription((int)$request->user_id, $request->put());
        return json(['code' => 200, 'msg' => 'Preferences updated', 'data' => $sub->toArray()]);
    }
}
