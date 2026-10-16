<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Observer;

use Magelan\Omnisend\Model\Config;
use Magelan\Omnisend\Model\Payload\Event;
use Magelan\Omnisend\Model\Queue\Handler\ContactHandler;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use Psr\Log\LoggerInterface;

/**
 * Order life cycle to Omnisend order events:
 *  - order placed           -> "placed order" (+ the buyer as a contact)
 *  - invoice paid           -> "paid for order" (for bank transfer: when the payment is booked)
 *  - shipment created       -> "order fulfilled", sent 10 minutes later so tracking numbers are on it
 *  - order canceled         -> "order canceled"
 *  - credit memo created    -> "order refunded"
 */
class OrderChanged implements ObserverInterface
{
    private const FULFILLED_DELAY = 600;

    public function __construct(
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            match ($observer->getEvent()->getName()) {
                'sales_model_service_quote_submit_success' => $this->placed($observer->getEvent()->getData('order')),
                'sales_order_invoice_save_after' => $this->invoiced($observer->getEvent()->getData('invoice')),
                'sales_order_shipment_save_after' => $this->shipped($observer->getEvent()->getData('shipment')),
                'sales_order_save_after' => $this->saved($observer->getEvent()->getData('order')),
                'sales_order_creditmemo_save_after' => $this->refunded($observer->getEvent()->getData('creditmemo')),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->error('Omnisend order event: ' . $e->getMessage());
        }
    }

    private function placed(mixed $order): void
    {
        if (!$order instanceof Order || !$order->getEntityId()) {
            return;
        }
        $storeId = (int)$order->getStoreId();
        if ($this->config->isOrderContactSyncEnabled($storeId) && $order->getCustomerEmail()) {
            $this->queue->add(Queue::TYPE_CONTACT, ContactHandler::ACTION_ORDER, (string)$order->getCustomerEmail(),
                $storeId, ['order_id' => (int)$order->getEntityId()]);
        }
        $this->queueEvent($order, Event::PLACED_ORDER);
    }

    private function invoiced(mixed $invoice): void
    {
        // A new paid invoice, or an open one that has just been paid (offline capture).
        if (!$invoice instanceof Invoice || (int)$invoice->getState() !== Invoice::STATE_PAID
            || (int)$invoice->getOrigData('state') === Invoice::STATE_PAID) {
            return;
        }
        $order = $invoice->getOrder();
        if ($order && !$this->queue->wasSent(Queue::TYPE_ORDER, Event::PAID_FOR_ORDER, (int)$order->getEntityId(),
            (int)$order->getStoreId())) {
            $this->queueEvent($order, Event::PAID_FOR_ORDER);
        }
    }

    private function shipped(mixed $shipment): void
    {
        if (!$shipment instanceof Shipment || $shipment->getOrigData('entity_id') !== null) {
            return;
        }
        $order = $shipment->getOrder();
        if ($order) {
            $this->queueEvent($order, Event::ORDER_FULFILLED, [], self::FULFILLED_DELAY);
        }
    }

    private function saved(mixed $order): void
    {
        if (!$order instanceof Order || $order->getState() !== Order::STATE_CANCELED
            || $order->getOrigData('state') === Order::STATE_CANCELED || $order->isObjectNew()) {
            return;
        }
        $this->queueEvent($order, Event::ORDER_CANCELED);
    }

    private function refunded(mixed $creditmemo): void
    {
        if (!$creditmemo instanceof Creditmemo || $creditmemo->getOrigData('entity_id') !== null) {
            return;
        }
        $order = $creditmemo->getOrder();
        if ($order) {
            $this->queueEvent($order, Event::ORDER_REFUNDED, ['creditmemo_id' => (int)$creditmemo->getId()], 0, false);
        }
    }

    private function queueEvent(Order $order, string $eventName, array $extra = [], int $delay = 0, bool $merge = true): void
    {
        $storeId = (int)$order->getStoreId();
        if (!$this->config->isOrderEventEnabled($storeId) || !$order->getEntityId()) {
            return;
        }
        $this->queue->add(
            Queue::TYPE_ORDER,
            $eventName,
            (int)$order->getEntityId(),
            $storeId,
            ['eventID' => Event::uuid(), 'time' => time()] + $extra,
            $delay,
            $merge
        );
    }
}
