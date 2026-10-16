<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Payload;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Product and category bodies for the Omnisend catalog. Product IDs are Magento entity IDs, the same
 * IDs the events use as productID, so Omnisend can join events to catalog products.
 */
class CatalogPayload
{
    private const MAX_VARIANTS = 500;

    public function __construct(
        private readonly ProductData $productData,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the product is not sold on its own (no page)
     */
    public function product(ProductInterface $product, int $storeId): ?array
    {
        $url = $this->productData->getUrl($product, $storeId);
        if (!$url) {
            return null;
        }
        /** @var Product $product */
        $currency = (string)$this->storeManager->getStore($storeId)->getBaseCurrencyCode();
        $images = $this->productData->getImageUrls($product, $storeId);
        $status = $this->productData->getStockStatus($product);
        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        if (!in_array($websiteId, array_map('intval', (array)$product->getWebsiteIds()), true)) {
            $status = 'notAvailable';
        }

        $variants = [];
        foreach ($this->getChildren($product, $storeId) as $child) {
            $variants[] = $this->variant($child, $storeId, $url);
            if (count($variants) >= self::MAX_VARIANTS) {
                break;
            }
        }
        if (!$variants) {
            $variants[] = $this->variant($product, $storeId, $url);
        }

        return [
            'id' => (string)$product->getId(),
            'title' => mb_substr((string)$product->getName(), 0, 255),
            'status' => $status,
            'url' => $url,
            'currency' => $currency,
            'description' => $this->productData->getDescription($product, 1000),
            'defaultImageUrl' => $images[0] ?? null,
            'images' => $images,
            'vendor' => $this->productData->getVendor($product),
            'type' => (string)$product->getTypeId(),
            'categoryIDs' => array_map(
                fn(array $category) => $category['id'],
                $this->productData->getCategories($product, $storeId)
            ),
            'createdAt' => $this->isoDate((string)$product->getCreatedAt()),
            'updatedAt' => $this->isoDate((string)$product->getUpdatedAt()),
            'variants' => $variants,
        ];
    }

    public function category(CategoryInterface $category): array
    {
        return [
            'categoryID' => (string)$category->getId(),
            'title' => mb_substr((string)$category->getName(), 0, 255),
        ];
    }

    private function variant(ProductInterface $variant, int $storeId, string $parentUrl): array
    {
        /** @var Product $variant */
        $price = $this->productData->getFinalPriceInclTax($variant, $storeId);
        $regular = $this->productData->getRegularPriceInclTax($variant, $storeId);
        $data = [
            'id' => (string)$variant->getId(),
            'title' => mb_substr((string)$variant->getName(), 0, 255),
            'sku' => mb_substr((string)$variant->getSku(), 0, 255),
            'price' => round($price, 2),
            'status' => $this->productData->getStockStatus($variant),
            'url' => $this->productData->getUrl($variant, $storeId) ?? $parentUrl,
            'defaultImageUrl' => $this->productData->getImageUrl($variant, $storeId),
        ];
        if ($regular > $price + 0.005) {
            $data['strikeThroughPrice'] = round($regular, 2);
        }

        return $data;
    }

    /**
     * @return ProductInterface[]
     */
    private function getChildren(Product $product, int $storeId): array
    {
        if ($product->getTypeId() !== 'configurable') {
            return [];
        }
        $children = [];
        foreach ($product->getTypeInstance()->getChildrenIds((int)$product->getId())[0] ?? [] as $childId) {
            try {
                $child = $this->productRepository->getById((int)$childId, false, $storeId);
            } catch (\Exception $e) {
                continue;
            }
            if ($child->getTypeId() === ProductType::TYPE_SIMPLE || $child->getTypeId() === ProductType::TYPE_VIRTUAL) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function isoDate(string $mysqlDate): ?string
    {
        $time = $mysqlDate !== '' ? strtotime($mysqlDate . ' UTC') : false;

        return $time ? gmdate('Y-m-d\TH:i:s\Z', $time) : null;
    }
}
