<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Setup\Patch\Data;

use Magelan\Omnisend\Model\CatalogRegistry;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * 1.0.3: products and categories already sent through the queue are known to Omnisend, so their next
 * change goes out as PUT/PATCH instead of a POST that would answer 409.
 */
class FillCatalogRegistry implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $queue = $this->setup->getTable(Queue::TABLE);
        // The last sent row of each item decides: an upsert that reached Omnisend (no note, or "created").
        $last = $connection->select()
            ->from($queue, ['queue_id' => 'MAX(queue_id)'])
            ->where('entity_type IN (?)', [Queue::TYPE_PRODUCT, Queue::TYPE_CATEGORY])
            ->where('status = ?', Queue::STATUS_SENT)
            ->group(['entity_type', 'store_id', 'object_id']);
        $select = $connection->select()
            ->from(['q' => $queue], ['entity_type', 'store_id', 'object_id'])
            ->join(['l' => $last], 'l.queue_id = q.queue_id', [])
            ->where('q.action = ?', Queue::ACTION_UPSERT)
            ->where('q.last_error IS NULL OR q.last_error = ?', 'created');
        $connection->query($connection->insertFromSelect(
            $select,
            $this->setup->getTable(CatalogRegistry::TABLE),
            ['entity_type', 'store_id', 'object_id'],
            \Magento\Framework\DB\Adapter\AdapterInterface::INSERT_IGNORE
        ));

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
