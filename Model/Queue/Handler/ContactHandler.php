<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Queue\Handler;

use Magelan\Omnisend\Model\Api\Client;
use Magelan\Omnisend\Model\Payload\ContactPayload;
use Magelan\Omnisend\Model\Queue\HandlerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Contacts. Subscription and account rows carry the full body; "order" rows are built from the order.
 */
class ContactHandler implements HandlerInterface
{
    /** Separate actions, so a pending account update never swallows a pending consent change. */
    public const ACTION_SUBSCRIPTION = 'subscription';
    public const ACTION_CUSTOMER = 'customer';
    public const ACTION_ORDER = 'order';

    public function __construct(
        private readonly Client $client,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ContactPayload $contactPayload
    ) {
    }

    public function handle(array $row): ?string
    {
        $storeId = (int)$row['store_id'];
        if ($row['action'] === self::ACTION_ORDER) {
            try {
                $order = $this->orderRepository->get((int)($row['payload']['order_id'] ?? 0));
            } catch (NoSuchEntityException $e) {
                return 'skipped: order not found';
            }
            $body = $this->contactPayload->fromOrder($order);
        } else {
            $body = (array)($row['payload'] ?? []);
        }
        if (empty($body['identifiers'][0]['id'])) {
            return 'skipped: no e-mail';
        }
        $this->client->post('contacts', $storeId, $body);

        return null;
    }
}
