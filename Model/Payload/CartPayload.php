<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Payload;

use Magelan\Omnisend\Model\RestoreUrl;
use Magento\Quote\Model\Quote;

/**
 * "added product to cart" and "started checkout": always the whole cart (Omnisend replaces its copy
 * of the cart with every event), its value incl. tax, and a signed link that restores it.
 */
class CartPayload
{
    public function __construct(
        private readonly LineItemBuilder $lineItemBuilder,
        private readonly RestoreUrl $restoreUrl
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the cart has no lines Omnisend can use
     */
    public function properties(Quote $quote, ?int $addedItemId = null): ?array
    {
        $lines = $this->lineItemBuilder->buildForQuote($quote);
        if (!$lines) {
            return null;
        }
        $properties = [
            'cartID' => (string)$quote->getId(),
            'currency' => (string)($quote->getQuoteCurrencyCode() ?: $quote->getStore()->getCurrentCurrencyCode()),
            'value' => $this->lineItemBuilder->getCartValue($quote),
            'abandonedCheckoutURL' => $this->restoreUrl->getUrl((int)$quote->getId(), (int)$quote->getStoreId()),
            'lineItems' => array_values($lines),
        ];
        if ($addedItemId !== null) {
            if (!isset($lines[$addedItemId])) {
                return null;
            }
            $properties['addedItem'] = $lines[$addedItemId];
        }

        return $properties;
    }
}
