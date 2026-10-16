<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Observer;

use Magelan\Omnisend\Model\CatalogQueue;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Product and category changes to the Omnisend catalog: saves, deletes, "Update attributes" mass
 * action, product import and stock status changes (an order selling the last piece).
 */
class CatalogChanged implements ObserverInterface
{
    public function __construct(
        private readonly CatalogQueue $catalogQueue,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        try {
            switch ($event->getName()) {
                case 'catalog_product_save_after':
                    $this->catalogQueue->products([(int)$event->getData('product')?->getId()]);
                    break;
                case 'catalog_product_delete_after':
                    $this->catalogQueue->products([(int)$event->getData('product')?->getId()], Queue::ACTION_DELETE);
                    break;
                case 'catalog_product_attribute_update_before':
                    $this->catalogQueue->products((array)$event->getData('product_ids'));
                    break;
                case 'catalog_product_import_bunch_save_after':
                    $this->catalogQueue->products($this->getImportedIds($event->getData('adapter'), (array)$event->getData('bunch')));
                    break;
                case 'cataloginventory_stock_item_save_after':
                    $item = $event->getData('item');
                    if ($item && $item->getOrigData('is_in_stock') !== null
                        && (int)$item->getOrigData('is_in_stock') !== (int)$item->getIsInStock()) {
                        $this->catalogQueue->products([(int)$item->getProductId()]);
                    }
                    break;
                case 'catalog_category_save_after':
                    $this->catalogQueue->categories([(int)$event->getData('category')?->getId()]);
                    break;
                case 'catalog_category_delete_after':
                    $this->catalogQueue->categories([(int)$event->getData('category')?->getId()], Queue::ACTION_DELETE);
                    break;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Omnisend catalog: ' . $e->getMessage());
        }
    }

    /**
     * @return int[]
     */
    private function getImportedIds(mixed $adapter, array $bunch): array
    {
        if (!is_object($adapter) || !method_exists($adapter, 'getNewSku')) {
            return [];
        }
        $ids = [];
        foreach ($bunch as $row) {
            $sku = (string)($row['sku'] ?? '');
            $data = $sku !== '' ? $adapter->getNewSku($sku) : null;
            if (!empty($data['entity_id'])) {
                $ids[] = (int)$data['entity_id'];
            }
        }

        return $ids;
    }
}
