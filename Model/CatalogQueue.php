<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model;

use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Framework\App\ResourceConnection;

/**
 * Queues catalog changes for every store view that owns an Omnisend catalog (one per API key).
 */
class CatalogQueue
{
    public function __construct(
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param int[] $productIds
     */
    public function products(array $productIds, string $action = Queue::ACTION_UPSERT): void
    {
        $storeIds = array_filter($this->config->getCatalogStoreIds(), fn(int $id) => $this->config->isProductSyncEnabled($id));
        if (!$storeIds || !array_filter($productIds)) {
            return;
        }
        $productIds = array_unique(array_filter(array_map('intval', $productIds)));
        if ($action === Queue::ACTION_UPSERT) {
            // A variant's price or stock is part of its configurable product in Omnisend.
            $productIds = array_unique(array_merge($productIds, $this->getParentIds($productIds)));
        }
        foreach ($storeIds as $storeId) {
            foreach ($productIds as $productId) {
                $this->queue->add(Queue::TYPE_PRODUCT, $action, $productId, $storeId);
            }
        }
    }

    /**
     * @param int[] $categoryIds
     */
    public function categories(array $categoryIds, string $action = Queue::ACTION_UPSERT): void
    {
        $storeIds = array_filter($this->config->getCatalogStoreIds(), fn(int $id) => $this->config->isCategorySyncEnabled($id));
        foreach ($storeIds as $storeId) {
            foreach (array_unique(array_map('intval', $categoryIds)) as $categoryId) {
                // 1 and 2 are the tree root and the default root category, not shop categories.
                if ($categoryId > 2) {
                    $this->queue->add(Queue::TYPE_CATEGORY, $action, $categoryId, $storeId);
                }
            }
        }
    }

    /**
     * @param int[] $childIds
     * @return int[]
     */
    private function getParentIds(array $childIds): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_super_link');
        if (!$connection->isTableExists($table)) {
            return [];
        }
        $linkField = $connection->tableColumnExists($this->resource->getTableName('catalog_product_entity'), 'row_id')
            ? 'row_id' : 'entity_id';
        $select = $connection->select()
            ->from(['l' => $table], [])
            ->join(['e' => $this->resource->getTableName('catalog_product_entity')], 'e.' . $linkField . ' = l.parent_id',
                ['e.entity_id'])
            ->where('l.product_id IN (?)', $childIds);

        return array_map('intval', $connection->fetchCol($select));
    }
}
