<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Queue\Handler;

use Magelan\Omnisend\Model\Api\Client;
use Magelan\Omnisend\Model\Payload\Event;
use Magelan\Omnisend\Model\Payload\OrderPayload;
use Magelan\Omnisend\Model\Queue\HandlerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Store\Model\App\Emulation;

/**
 * Order events. The payload only holds the event ID and time (and the credit memo for a refund);
 * the order itself is read when sending.
 */
class OrderHandler implements HandlerInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly OrderPayload $orderPayload,
        private readonly Emulation $emulation
    ) {
    }

    public function handle(array $row): ?string
    {
        $eventName = (string)$row['action'];
        if (!in_array($eventName, Event::ORDER_EVENTS, true)) {
            return 'skipped: unknown order event ' . $eventName;
        }
        try {
            $order = $this->orderRepository->get((int)$row['object_id']);
        } catch (NoSuchEntityException $e) {
            return 'skipped: order not found';
        }
        $payload = (array)($row['payload'] ?? []);
        $creditmemo = null;
        if (!empty($payload['creditmemo_id'])) {
            try {
                /** @var Creditmemo $creditmemo */
                $creditmemo = $this->creditmemoRepository->get((int)$payload['creditmemo_id']);
            } catch (NoSuchEntityException $e) {
                $creditmemo = null;
            }
        }

        $storeId = (int)$order->getStoreId();
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $body = Event::build(
                $eventName,
                $this->orderPayload->contact($order),
                $this->orderPayload->properties($order, $eventName, $creditmemo),
                $payload['eventID'] ?? null,
                isset($payload['time']) ? (int)$payload['time'] : null
            );
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        if (empty($body['contact']['email'])) {
            return 'skipped: order without e-mail';
        }
        $this->client->post('events', (int)$row['store_id'], $body);

        return null;
    }
}
