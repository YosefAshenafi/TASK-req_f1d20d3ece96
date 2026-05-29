<?php
declare(strict_types=1);
namespace app\service;

use app\model\Order;
use app\model\OrderRefund;
use app\model\InvoiceCorrection;
use app\service\EncryptionService;
use app\exception\OrderStateException;
use app\exception\ForbiddenException;
use app\exception\NotFoundException;
use think\facade\Db;
use think\facade\Log;

class OrderService
{
    private const TRANSITIONS = [
        'placed'          => ['pending_payment', 'canceled'],
        'pending_payment' => ['paid', 'canceled'],
        'paid'            => ['ticketing', 'canceled'],
        'ticketing'       => ['ticketed'],
        'ticketed'        => ['closed'],
        'canceled'        => [],
        'closed'          => [],
    ];

    private const TIMESTAMP_MAP = [
        'pending_payment' => 'pending_payment_at',
        'paid'            => 'paid_at',
        'ticketed'        => 'ticketed_at',
        'canceled'        => 'canceled_at',
        'closed'          => 'closed_at',
    ];

    public function transition(int $orderId, string $target, int $userId, string $role): Order
    {
        return Db::transaction(function () use ($orderId, $target, $userId, $role) {
            $order = Order::lock(true)->find($orderId);
            if (!$order) throw new NotFoundException('Order not found');

            $current = $order->status;

            if ($current === 'closed' && $target !== 'closed') {
                throw new OrderStateException('Closed orders are immutable');
            }

            $allowed = self::TRANSITIONS[$current] ?? [];

            // Admin can always cancel
            if ($target === 'canceled' && $role === 'admin') {
                // allowed
            } elseif (!in_array($target, $allowed, true)) {
                throw new OrderStateException("Cannot transition order from '{$current}' to '{$target}'");
            }

            $updates = ['status' => $target];
            if (isset(self::TIMESTAMP_MAP[$target])) {
                $updates[self::TIMESTAMP_MAP[$target]] = date('Y-m-d H:i:s');
            }

            Order::where('id', $orderId)->update($updates);
            Log::info('order_transition', ['id' => $orderId, 'from' => $current, 'to' => $target, 'by' => $userId]);

            return Order::find($orderId);
        });
    }

    public function autoCancelExpiredOrders(): int
    {
        $cutoff  = date('Y-m-d H:i:s', time() - 1800); // 30 minutes ago
        $expired = Order::where('status', 'pending_payment')
            ->where('pending_payment_at', '<=', $cutoff)
            ->select();

        $count = 0;
        foreach ($expired as $order) {
            try {
                $this->transition((int)$order->id, 'canceled', 0, 'admin');
                $count++;
            } catch (\Throwable $e) {
                Log::error('auto_cancel_failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        Log::info('auto_cancel_completed', ['canceled_count' => $count]);
        return $count;
    }

    public function refund(int $orderId, int $adminId, string $role, ?string $reason = null): Order
    {
        if ($role !== 'admin') {
            throw new ForbiddenException('Only administrators may issue refunds');
        }

        $order = Order::find($orderId);
        if (!$order) throw new NotFoundException('Order not found');

        if ($order->status !== 'paid') {
            throw new OrderStateException('Refunds are only allowed on orders in the Paid state');
        }

        Db::transaction(function () use ($orderId, $adminId, $reason) {
            OrderRefund::create(['order_id' => $orderId, 'refunded_by' => $adminId, 'reason' => $reason]);
            $this->transition($orderId, 'canceled', $adminId, 'admin');
        });

        return Order::find($orderId);
    }

    public function requestCorrection(int $orderId, array $patch, int $userId): InvoiceCorrection
    {
        $order = Order::find($orderId);
        if (!$order) throw new NotFoundException('Order not found');

        if ($order->status !== 'closed') {
            throw new OrderStateException('Invoice corrections are only allowed on closed orders');
        }

        // Closed-order corrections are scoped to invoice_address only.
        if (array_key_exists('invoice_contact', $patch)) {
            throw new \app\exception\AppException(
                'invoice_contact cannot be corrected on closed orders; only invoice_address corrections are accepted'
            );
        }

        $filtered = array_intersect_key($patch, array_flip(['invoice_address']));
        if (empty($filtered)) {
            throw new \app\exception\AppException(
                'No correctable fields provided; submit invoice_address to request a correction'
            );
        }

        $encPatch = ['invoice_address_enc' => EncryptionService::encrypt((string)$filtered['invoice_address'])];

        return InvoiceCorrection::create([
            'order_id'     => $orderId,
            'requested_by' => $userId,
            'field_patch'  => $encPatch,
            'scope'        => 'invoice_address',
        ]);
    }

    public function reviewCorrection(int $correctionId, string $decision, string $notes, int $reviewerId): InvoiceCorrection
    {
        $correction = InvoiceCorrection::find($correctionId);
        if (!$correction) throw new NotFoundException('Correction request not found');

        if ($correction->status !== 'pending') {
            throw new OrderStateException('This correction has already been reviewed');
        }

        if (empty(trim($notes))) {
            throw new \app\exception\AppException('Decision notes are required');
        }

        InvoiceCorrection::where('id', $correctionId)->update([
            'status'         => $decision,
            'reviewed_by'    => $reviewerId,
            'decision_notes' => $notes,
        ]);

        if ($decision === 'approved') {
            $patch   = json_decode($correction->field_patch, true) ?? [];
            $dbPatch = [];
            foreach ($patch as $field => $value) {
                if ($field === 'invoice_address_enc') {
                    // Already-encrypted address — apply directly
                    $dbPatch['invoice_address_enc'] = $value;
                } elseif ($field === 'invoice_address') {
                    // Legacy plaintext format
                    $dbPatch['invoice_address_enc'] = EncryptionService::encrypt((string)$value);
                }
                // invoice_contact fields are excluded — corrections only apply to invoice_address
            }
            if (!empty($dbPatch)) {
                Order::where('id', $correction->order_id)->update($dbPatch);
            }
        }

        return InvoiceCorrection::find($correctionId);
    }
}
