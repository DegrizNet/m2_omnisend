<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Queue\Handler;

use Magelan\Omnisend\Model\Api\Client;
use Magelan\Omnisend\Model\Queue\HandlerInterface;

/**
 * Events whose body was complete when queued (the cart events: the cart changes afterwards).
 */
class EventHandler implements HandlerInterface
{
    public function __construct(
        private readonly Client $client
    ) {
    }

    public function handle(array $row): ?string
    {
        if (!is_array($row['payload'] ?? null) || empty($row['payload']['eventName'])) {
            return 'skipped: empty payload';
        }
        $this->client->post('events', (int)$row['store_id'], $row['payload']);

        return null;
    }
}
