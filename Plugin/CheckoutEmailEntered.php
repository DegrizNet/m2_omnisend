<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Plugin;

use Magelan\Omnisend\Model\CartEvents;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;

/**
 * A guest's e-mail reaches the server first through the "is this e-mail registered?" call the checkout
 * makes while it is typed. That is when an anonymous checkout becomes a known one.
 */
class CheckoutEmailEntered
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartEvents $cartEvents,
        private readonly State $appState
    ) {
    }

    public function afterIsEmailAvailable(
        AccountManagementInterface $subject,
        $result,
        $customerEmail,
        $websiteId = null
    ) {
        try {
            if (!in_array($this->appState->getAreaCode(), [Area::AREA_FRONTEND, Area::AREA_WEBAPI_REST], true)) {
                return $result;
            }
            $quote = $this->checkoutSession->getQuote();
            if ($quote->getId() && !$quote->getCustomerId()) {
                $this->cartEvents->startedCheckout($quote, (string)$customerEmail);
            }
        } catch (\Throwable $e) {
            // Never break the e-mail check because of Omnisend.
        }

        return $result;
    }
}
