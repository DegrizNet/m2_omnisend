<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Products and categories Omnisend already has, so each one is sent with the right method the first time.
 * Omnisend counts every 404 and 409 as a failed sync and shows "Sync issue found" in the store settings,
 * so "try PUT, then POST on 404" is not good enough even though it ends up correct.
 */
class CatalogRegistry
{
    public const TABLE = 'magelan_omnisend_catalog';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function has(string $type, int $storeId, string $objectId): bool
    {
        $connection = $this->resource->getConnection();

        return (bool)$connection->fetchOne(
            $connection->select()->from($this->getTable(), 'object_id')
                ->where('entity_type = ?', $type)
                ->where('store_id = ?', $storeId)
                ->where('object_id = ?', $objectId)
                ->limit(1)
        );
    }

    public function add(string $type, int $storeId, string $objectId): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->getTable(),
            ['entity_type' => $type, 'store_id' => $storeId, 'object_id' => $objectId],
            ['object_id']
        );
    }

    public function remove(string $type, int $storeId, string $objectId): void
    {
        $this->resource->getConnection()->delete($this->getTable(), [
            'entity_type = ?' => $type,
            'store_id = ?' => $storeId,
            'object_id = ?' => $objectId,
        ]);
    }

    private function getTable(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
