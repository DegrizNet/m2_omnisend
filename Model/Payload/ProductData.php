<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Payload;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Product facts shared by the cart, order and catalog payloads.
 */
class ProductData
{
    /** @var array<string, array<int, array{id: string, title: string}>> */
    private array $categoryCache = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CatalogHelper $catalogHelper,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    public function getImageUrl(ProductInterface $product, int $storeId): ?string
    {
        $image = (string)$product->getData('image');
        if ($image === '' || $image === 'no_selection') {
            return null;
        }
        $mediaUrl = $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

        return rtrim($mediaUrl, '/') . '/catalog/product/' . ltrim($image, '/');
    }

    /**
     * @return string[] all gallery images, main image first
     */
    public function getImageUrls(ProductInterface $product, int $storeId, int $limit = 10): array
    {
        $urls = [];
        if ($main = $this->getImageUrl($product, $storeId)) {
            $urls[] = $main;
        }
        $mediaUrl = rtrim($this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');
        $gallery = $product->getData('media_gallery');
        foreach (is_array($gallery) ? (array)($gallery['images'] ?? []) : [] as $image) {
            if (!empty($image['disabled']) || ($image['media_type'] ?? 'image') !== 'image' || empty($image['file'])) {
                continue;
            }
            $url = $mediaUrl . '/catalog/product/' . ltrim((string)$image['file'], '/');
            if (!in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return array_slice($urls, 0, $limit);
    }

    /**
     * Product page in the given store view; children that are not visible on their own link to nothing.
     */
    public function getUrl(ProductInterface $product, int $storeId): ?string
    {
        if ((int)$product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
            return null;
        }
        /** @var Product $product */
        return $product->getUrlModel()->getUrl($product, ['_scope' => $storeId, '_nosid' => true]);
    }

    public function getShortDescription(ProductInterface $product, int $length): ?string
    {
        return $this->plainText((string)($product->getData('short_description') ?: $product->getData('description')), $length);
    }

    public function getDescription(ProductInterface $product, int $length): ?string
    {
        return $this->plainText((string)($product->getData('description') ?: $product->getData('short_description')), $length);
    }

    public function getVendor(ProductInterface $product): ?string
    {
        if (!$product->getData('manufacturer')) {
            return null;
        }
        /** @var Product $product */
        $text = $product->getAttributeText('manufacturer');

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * @return array<int, array{id: string, title: string}>
     */
    public function getCategories(ProductInterface $product, int $storeId): array
    {
        $ids = array_filter(array_map('intval', (array)$product->getCategoryIds()), fn($id) => $id > 2);
        if (!$ids) {
            return [];
        }
        sort($ids);
        $cacheKey = $storeId . ':' . implode(',', $ids);
        if (!isset($this->categoryCache[$cacheKey])) {
            $collection = $this->categoryCollectionFactory->create()
                ->setStoreId($storeId)
                ->addAttributeToSelect('name')
                ->addAttributeToFilter('entity_id', ['in' => $ids])
                ->addIsActiveFilter();
            $categories = [];
            foreach ($collection as $category) {
                $categories[] = ['id' => (string)$category->getId(), 'title' => (string)$category->getName()];
            }
            $this->categoryCache[$cacheKey] = $categories;
        }

        return $this->categoryCache[$cacheKey];
    }

    public function getRegularPriceInclTax(ProductInterface $product, int $storeId): float
    {
        /** @var Product $product */
        return $this->inclTax($product, (float)$product->getPrice(), $storeId);
    }

    /**
     * Price after special price and catalog price rules, as the shopper sees it.
     * Call inside store emulation so rules and tax use the right website and customer group.
     */
    public function getFinalPriceInclTax(ProductInterface $product, int $storeId): float
    {
        /** @var Product $product */
        // getValue() is on the same basis as the price attribute (tax as entered); tax is added below.
        $final = (float)$product->getPriceInfo()->getPrice('final_price')->getValue();
        if ($final <= 0) {
            $final = (float)$product->getFinalPrice();
        }

        return $this->inclTax($product, $final, $storeId);
    }

    /**
     * Omnisend product status: inStock, outOfStock or notAvailable.
     */
    public function getStockStatus(ProductInterface $product): string
    {
        if ((int)$product->getStatus() !== Product\Attribute\Source\Status::STATUS_ENABLED) {
            return 'notAvailable';
        }
        try {
            $stockItem = $this->stockRegistry->getStockItem((int)$product->getId());

            return $stockItem->getIsInStock() ? 'inStock' : 'outOfStock';
        } catch (\Exception $e) {
            return 'inStock';
        }
    }

    private function inclTax(Product $product, float $price, int $storeId): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        return (float)$this->catalogHelper->getTaxPrice($product, $price, true, null, null, null, $storeId);
    }

    private function plainText(string $html, int $length): ?string
    {
        $text = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h\d)[^>]*>/i', ' ', $html) ?? ''),
            ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }
}
