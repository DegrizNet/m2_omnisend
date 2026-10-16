<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Payload;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use Psr\Log\LoggerInterface;

/**
 * Omnisend lineItems for carts and orders, with the prices the shopper sees (incl. tax).
 * One line per visible cart line; for a configurable product the variant is the chosen child.
 */
class LineItemBuilder
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductData $productData,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> keyed by quote item ID
     */
    public function buildForQuote(Quote $quote): array
    {
        $lines = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $line = $this->buildQuoteLine($item, (int)$quote->getStoreId());
            if ($line) {
                $lines[(int)$item->getId()] = $line;
            }
        }

        return $lines;
    }

    /**
     * Cart value as the shopper sees it: rows incl. tax, minus discounts, without shipping.
     */
    public function getCartValue(Quote $quote): float
    {
        $value = 0.0;
        foreach ($quote->getAllVisibleItems() as $item) {
            $value += $this->getRowTotalInclTax($item) - (float)$item->getDiscountAmount();
        }

        return round(max(0.0, $value), 2);
    }

    public function buildQuoteLine(QuoteItem $item, int $storeId): ?array
    {
        $product = $this->loadProduct((int)$item->getProductId(), $storeId);
        if (!$product) {
            return null;
        }
        $variant = $this->getQuoteVariant($item, $storeId) ?? $product;
        $qty = max(1.0, (float)$item->getQty());
        $price = $this->getPriceInclTax($item);

        return $this->line($product, $variant, $storeId, [
            'productSKU' => (string)$item->getSku(),
            'productTitle' => (string)$item->getName(),
            'productPrice' => round($price, 2),
            'productDiscount' => round((float)$item->getDiscountAmount() / $qty, 2),
            'productQuantity' => (int)$qty,
        ], $price);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildForOrder(OrderInterface $order): array
    {
        $storeId = (int)$order->getStoreId();
        $lines = [];
        /** @var OrderItem $item */
        foreach ($order->getAllVisibleItems() as $item) {
            $qty = max(1.0, (float)$item->getQtyOrdered());
            $price = (float)($item->getPriceInclTax() ?: $item->getPrice());
            $product = $this->loadProduct((int)$item->getProductId(), $storeId);
            $variantId = (int)$item->getProductId();
            foreach ($item->getChildrenItems() as $child) {
                $variantId = (int)$child->getProductId();
                break;
            }
            $variant = $variantId !== (int)$item->getProductId() ? $this->loadProduct($variantId, $storeId) : null;
            $fields = [
                'productSKU' => (string)$item->getSku(),
                'productTitle' => (string)$item->getName(),
                'productPrice' => round($price, 2),
                'productDiscount' => round((float)$item->getDiscountAmount() / $qty, 2),
                'productQuantity' => (int)$qty,
            ];
            if (!$product) {
                // Deleted since: the order still has what Omnisend needs.
                $lines[] = $fields + [
                    'productID' => (string)$item->getProductId(),
                    'productVariantID' => (string)$variantId,
                    'productVariantTitle' => (string)$item->getName(),
                ];
                continue;
            }
            $line = $this->line($product, $variant ?? $product, $storeId, $fields, $price);
            $line['productVariantID'] = (string)$variantId;
            $lines[] = $line;
        }

        return $lines;
    }

    private function line(
        ProductInterface $product,
        ProductInterface $variant,
        int $storeId,
        array $fields,
        float $price
    ): array {
        $imageUrl = $this->productData->getImageUrl($product, $storeId);
        $line = [
            'productID' => (string)$product->getId(),
            'productVariantID' => (string)$variant->getId(),
            'productVariantTitle' => (string)($fields['productTitle'] ?? $variant->getName()),
            'productDescription' => $this->productData->getShortDescription($product, 300),
            'productURL' => $this->productData->getUrl($product, $storeId),
            'productImageURL' => $imageUrl,
            'productVariantImageURL' => $this->productData->getImageUrl($variant, $storeId) ?? $imageUrl,
            'productCategories' => $this->productData->getCategories($product, $storeId),
            'productVendor' => $this->productData->getVendor($product),
            'productWeight' => (float)$variant->getWeight() ?: null,
        ] + $fields;

        $regularPrice = $this->productData->getRegularPriceInclTax($variant, $storeId);
        if ($regularPrice > $price + 0.005) {
            $line['productStrikeThroughPrice'] = round($regularPrice, 2);
        }

        return $line;
    }

    private function getQuoteVariant(QuoteItem $item, int $storeId): ?ProductInterface
    {
        $childId = null;
        foreach ($item->getChildren() as $child) {
            $childId = (int)$child->getProductId();
            break;
        }
        if (!$childId && ($option = $item->getOptionByCode('simple_product'))) {
            $childId = (int)$option->getValue();
        }
        if (!$childId || $childId === (int)$item->getProductId()) {
            return null;
        }

        return $this->loadProduct($childId, $storeId);
    }

    private function loadProduct(int $productId, int $storeId): ?ProductInterface
    {
        try {
            return $this->productRepository->getById($productId, false, $storeId);
        } catch (\Exception $e) {
            $this->logger->info('Omnisend: product ' . $productId . ' not found');
            return null;
        }
    }

    /**
     * price_incl_tax is only filled after totals were collected; fall back to the row total.
     */
    private function getPriceInclTax(QuoteItem $item): float
    {
        $price = (float)$item->getPriceInclTax();
        if ($price > 0) {
            return $price;
        }

        return $this->getRowTotalInclTax($item) / max(1.0, (float)$item->getQty());
    }

    private function getRowTotalInclTax(QuoteItem $item): float
    {
        $rowTotal = (float)$item->getRowTotalInclTax();
        if ($rowTotal > 0) {
            return $rowTotal;
        }

        return (float)$item->getPriceInclTax() * (float)$item->getQty()
            ?: (float)$item->getRowTotal() + (float)$item->getTaxAmount();
    }
}
