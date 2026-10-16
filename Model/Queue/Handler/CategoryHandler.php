<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Queue\Handler;

use Magelan\Omnisend\Model\Api\ApiException;
use Magelan\Omnisend\Model\Api\Client;
use Magelan\Omnisend\Model\CatalogRegistry;
use Magelan\Omnisend\Model\Payload\CatalogPayload;
use Magelan\Omnisend\Model\Queue\HandlerInterface;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Catalog categories: POST creates a category Omnisend does not have yet, PATCH renames one it has.
 * Which one it has is kept in CatalogRegistry; a wrong guess (409 on POST, 404 on PATCH) is corrected
 * with the other method. A deleted or inactive category is removed only if it was sent before.
 */
class CategoryHandler implements HandlerInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CatalogPayload $catalogPayload,
        private readonly CatalogRegistry $registry
    ) {
    }

    public function handle(array $row): ?string
    {
        $storeId = (int)$row['store_id'];
        $categoryId = (string)$row['object_id'];
        $path = 'product-categories/' . rawurlencode($categoryId);

        if ($row['action'] !== Queue::ACTION_DELETE) {
            try {
                $category = $this->categoryRepository->get((int)$categoryId, $storeId);
            } catch (NoSuchEntityException $e) {
                $category = null;
            }
            if ($category && $category->getIsActive()) {
                $body = $this->catalogPayload->category($category);
                if ($this->registry->has(Queue::TYPE_CATEGORY, $storeId, $categoryId)) {
                    $this->rename($path, $storeId, $body);
                } else {
                    $this->create($path, $storeId, $body);
                }
                $this->registry->add(Queue::TYPE_CATEGORY, $storeId, $categoryId);

                return null;
            }
        }

        // An inactive category that was never sent: a DELETE would be one more 404.
        if (!$this->registry->has(Queue::TYPE_CATEGORY, $storeId, $categoryId)) {
            return 'not in Omnisend';
        }
        $note = null;
        try {
            $this->client->delete($path, $storeId);
        } catch (ApiException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
            $note = 'not in Omnisend';
        }
        $this->registry->remove(Queue::TYPE_CATEGORY, $storeId, $categoryId);

        return $note;
    }

    private function create(string $path, int $storeId, array $body): void
    {
        try {
            $this->client->post('product-categories', $storeId, $body);
        } catch (ApiException $e) {
            if ($e->getStatus() !== 409) {
                throw $e;
            }
            $this->client->request('PATCH', $path, $storeId, ['title' => $body['title']]);
        }
    }

    private function rename(string $path, int $storeId, array $body): void
    {
        try {
            $this->client->request('PATCH', $path, $storeId, ['title' => $body['title']]);
        } catch (ApiException $e) {
            // Removed in Omnisend by hand: create it again.
            if (!$e->isNotFound()) {
                throw $e;
            }
            $this->client->post('product-categories', $storeId, $body);
        }
    }
}
