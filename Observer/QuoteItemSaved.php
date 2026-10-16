<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Observer;

use Magelan\Omnisend\Model\CartEvents;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Item;

/**
 * "added product to cart" when a cart line is added or its quantity goes up. Children of a
 * configurable product are separate quote items; only the visible line counts.
 */
class QuoteItemSaved implements ObserverInterface
{
    public function __construct(
        private readonly CartEvents $cartEvents
    ) {
    }

    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getData('item');
        if (!$item instanceof Item || $item->getParentItemId() || $item->getParentItem()) {
            return;
        }
        $isNew = $item->isObjectNew() || $item->getOrigData('item_id') === null;
        $qtyUp = (float)$item->getQty() > (float)$item->getOrigData('qty');
        if ($isNew || $qtyUp) {
            $this->cartEvents->addedToCart($item);
        }
    }
}
