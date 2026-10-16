<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Queue\Handler;

use Magelan\Omnisend\Model\Api\ApiException;
use Magelan\Omnisend\Model\Api\Client;
use Magelan\Omnisend\Model\CatalogRegistry;
use Magelan\Omnisend\Model\Payload\CatalogPayload;
use Magelan\Omnisend\Model\Queue\HandlerInterface;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation;

/**
 * Catalog products: POST creates a product Omnisend does not have yet, PUT replaces one it has, DELETE removes it.
 * Which one it has is kept in CatalogRegistry; a wrong guess (404 on PUT, 409 on POST) is corrected with the
 * other method. A product without its own page (not visible individually) is removed from Omnisend rather than sent,
 * and only if it was sent before.
 */
class ProductHandler implements HandlerInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CatalogPayload $catalogPayload,
        private readonly Emulation $emulation,
        private readonly CatalogRegistry $registry
    ) {
    }

    public function handle(array $row): ?string
    {
        $storeId = (int)$row['store_id'];
        $productId = (string)$row['object_id'];
        if ($row['action'] === Queue::ACTION_DELETE) {
            return $this->delete($productId, $storeId);
        }
        try {
            // Fresh load: the repository cache may hold the product from before the save.
            $product = $this->productRepository->getById((int)$productId, false, $storeId, true);
        } catch (NoSuchEntityException $e) {
            return $this->delete($productId, $storeId);
        }

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $body = $this->catalogPayload->product($product, $storeId);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        if ($body === null) {
            return $this->delete($productId, $storeId) ?? 'not visible: removed from Omnisend';
        }

        $note = $this->registry->has(Queue::TYPE_PRODUCT, $storeId, $productId)
            ? $this->replace($productId, $storeId, $body)
            : $this->create($productId, $storeId, $body);
        $this->registry->add(Queue::TYPE_PRODUCT, $storeId, $productId);

        return $note;
    }

    private function create(string $productId, int $storeId, array $body): ?string
    {
        try {
            $this->client->post('products', $storeId, $body);
        } catch (ApiException $e) {
            if ($e->getStatus() !== 409) {
                throw $e;
            }
            $this->client->put('products/' . rawurlencode($productId), $storeId, $body);

            return null;
        }

        return 'created';
    }

    private function replace(string $productId, int $storeId, array $body): ?string
    {
        try {
            $this->client->put('products/' . rawurlencode($productId), $storeId, $body);
        } catch (ApiException $e) {
            // Removed in Omnisend by hand: create it again.
            if (!$e->isNotFound()) {
                throw $e;
            }
            $this->client->post('products', $storeId, $body);

            return 'created';
        }

        return null;
    }

    private function delete(string $productId, int $storeId): ?string
    {
        // Variants and hidden products are never sent, and a DELETE for them would be one more 404.
        if (!$this->registry->has(Queue::TYPE_PRODUCT, $storeId, $productId)) {
            return 'not in Omnisend';
        }
        $note = null;
        try {
            $this->client->delete('products/' . rawurlencode($productId), $storeId);
        } catch (ApiException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
            $note = 'not in Omnisend';
        }
        $this->registry->remove(Queue::TYPE_PRODUCT, $storeId, $productId);

        return $note;
    }
}
