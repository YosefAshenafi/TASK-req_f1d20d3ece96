<?php
declare(strict_types=1);
namespace app\service;

use app\model\Shipment;
use app\model\ShipmentPackage;
use app\model\ShipmentEvent;
use app\model\UserSubscription;
use app\exception\NotFoundException;
use think\facade\Db;
use think\facade\Log;

class FulfillmentService
{
    private const EVENT_PREF_MAP = [
        'delivered'  => 'notify_arrival',
        'exception'  => 'notify_exception',
        'in_transit' => 'notify_delay',
    ];

    public function createShipment(int $orderId, array $packages, int $userId): Shipment
    {
        return Db::transaction(function () use ($orderId, $packages, $userId) {
            $shipment = Shipment::create([
                'order_id'   => $orderId,
                'created_by' => $userId,
            ]);

            foreach ($packages as $i => $pkg) {
                ShipmentPackage::create([
                    'shipment_id'    => $shipment->id,
                    'package_ref'    => $pkg['package_ref'] ?? ('PKG-' . ($i + 1)),
                    'carrier_name'   => $pkg['carrier_name']   ?? null,
                    'tracking_number'=> $pkg['tracking_number'] ?? null,
                ]);
            }

            Log::info('shipment_created', ['shipment_id' => $shipment->id, 'order_id' => $orderId, 'packages' => count($packages)]);
            return Shipment::with(['packages'])->find($shipment->id);
        });
    }

    public function recordScanEvent(int $shipmentId, string $eventType, ?string $location, ?string $note, int $userId): ShipmentEvent
    {
        $shipment = Shipment::lock(true)->find($shipmentId);
        if (!$shipment) throw new NotFoundException('Shipment not found');

        $event = ShipmentEvent::create([
            'shipment_id' => $shipmentId,
            'event_type'  => $eventType,
            'location'    => $location,
            'note'        => $note,
            'entered_by'  => $userId,
            'occurred_at' => date('Y-m-d H:i:s'),
        ]);

        // Advance shipment status
        $statusMap = ['delivered' => 'delivered', 'exception' => 'exception', 'dispatched' => 'in_transit', 'in_transit' => 'in_transit'];
        if (isset($statusMap[$eventType])) {
            Shipment::where('id', $shipmentId)->update(['status' => $statusMap[$eventType]]);
        }

        $this->maybeCreateArrivalReminder($shipmentId, $eventType, $shipment->order_id);
        Log::info('scan_event', ['shipment_id' => $shipmentId, 'type' => $eventType]);

        return $event;
    }

    public function confirmDelivery(int $shipmentId, int $userId): Shipment
    {
        $shipment = Shipment::find($shipmentId);
        if (!$shipment) throw new NotFoundException('Shipment not found');

        Shipment::where('id', $shipmentId)->update(['status' => 'delivered']);
        ShipmentPackage::where('shipment_id', $shipmentId)->update(['status' => 'delivered']);
        Log::info('delivery_confirmed', ['shipment_id' => $shipmentId, 'by' => $userId]);

        // Delivery confirmation is an arrival event — notify the order creator.
        $this->maybeCreateArrivalReminder($shipmentId, 'delivered', (int)$shipment->order_id);

        return Shipment::with(['packages'])->find($shipmentId);
    }

    public function recordException(int $shipmentId, string $note, int $userId): ShipmentEvent
    {
        return $this->recordScanEvent($shipmentId, 'exception', null, $note, $userId);
    }

    public function shouldNotify(int $userId, string $eventType): bool
    {
        $prefCol = self::EVENT_PREF_MAP[$eventType] ?? null;
        if (!$prefCol) return false;

        $sub = UserSubscription::where('user_id', $userId)->find();
        if (!$sub) return true; // default to notify

        return (bool)$sub->getData($prefCol);
    }

    public function updateSubscription(int $userId, array $prefs): UserSubscription
    {
        $allowed = ['notify_arrival', 'notify_exception', 'notify_delay'];
        $data    = array_intersect_key($prefs, array_flip($allowed));
        $data    = array_map(fn($v) => (int)(bool)$v, $data);

        $existing = UserSubscription::where('user_id', $userId)->find();
        if ($existing) {
            UserSubscription::where('user_id', $userId)->update($data);
        } else {
            UserSubscription::create(array_merge(['user_id' => $userId], $data));
        }

        return UserSubscription::where('user_id', $userId)->find();
    }

    private function maybeCreateArrivalReminder(int $shipmentId, string $eventType, int $orderId): void
    {
        if ($eventType !== 'delivered') return;
        try {
            // Resolve the order creator so recipient_id satisfies the FK constraint
            $order = \think\facade\Db::table('orders')->where('id', $orderId)->field('created_by')->find();
            if (!$order || empty($order['created_by'])) return;

            \think\facade\Db::table('notifications')->insert([
                'recipient_id' => (int)$order['created_by'],
                'type'         => 'arrival_reminder',
                'message'      => "Shipment #{$shipmentId} has been delivered",
                'entity_type'  => 'shipment',
                'entity_id'    => $shipmentId,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('notification_insert_failed', ['error' => $e->getMessage()]);
        }
    }
}
