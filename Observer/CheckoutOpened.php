<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Observer;

use Magelan\Omnisend\Model\CartEvents;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * "started checkout" when the checkout page opens and the visitor is already known. Listens to the
 * standard checkout and to Swissup Firecheckout, which serves /checkout from its own action.
 */
class CheckoutOpened implements ObserverInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartEvents $cartEvents
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Throwable $e) {
            return;
        }
        $this->cartEvents->startedCheckout($quote);
    }
}
