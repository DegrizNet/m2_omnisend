<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Controller\Restore;

use Magelan\Omnisend\Model\RestoreUrl;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Url\EncoderInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * abandonedCheckoutURL target: puts the cart from the Omnisend e-mail back into the visitor's
 * session and opens the checkout. Query parameters (utm_*, omnisendContactID) are kept on the way.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly RestoreUrl $restoreUrl,
        private readonly QuoteFactory $quoteFactory,
        private readonly QuoteResource $quoteResource,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $url,
        private readonly EncoderInterface $urlEncoder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Redirect
    {
        $quoteId = (int)$this->request->getParam(RestoreUrl::PARAM_CART);
        $signature = (string)$this->request->getParam(RestoreUrl::PARAM_SIGNATURE);

        if (!$this->restoreUrl->isValid($quoteId, $signature)) {
            return $this->redirect('checkout/cart');
        }

        $quote = $this->quoteFactory->create();
        $this->quoteResource->load($quote, $quoteId);
        if (!$quote->getId() || !$quote->getIsActive() || !$quote->getItemsCount()) {
            // Already ordered or emptied: show whatever the visitor has now.
            return $this->redirect('checkout/cart');
        }

        if ((int)$quote->getStoreId() !== (int)$this->storeManager->getStore()->getId()) {
            return $this->redirectFactory->create()->setUrl(
                $this->restoreUrl->getUrl($quoteId, (int)$quote->getStoreId()) . $this->getTrackingQuery('&')
            );
        }

        try {
            if ($quote->getCustomerId()) {
                return $this->restoreCustomerQuote($quote);
            }

            return $this->restoreGuestQuote($quote);
        } catch (\Throwable $e) {
            $this->logger->error('Omnisend cart restore failed: ' . $e->getMessage(), ['quote' => $quoteId]);
            return $this->redirect('checkout/cart');
        }
    }

    /**
     * A customer's cart is only opened after they log in as that customer; Magento then
     * loads their active cart by itself.
     */
    private function restoreCustomerQuote(Quote $quote): Redirect
    {
        if (!$this->customerSession->isLoggedIn()) {
            return $this->redirect('customer/account/login', [
                'referer' => $this->urlEncoder->encode($this->url->getUrl('checkout')),
            ]);
        }
        if ((int)$this->customerSession->getCustomerId() !== (int)$quote->getCustomerId()) {
            return $this->redirect('checkout/cart');
        }

        return $this->redirect('checkout');
    }

    private function restoreGuestQuote(Quote $quote): Redirect
    {
        if ($this->customerSession->isLoggedIn()) {
            // Logged in meanwhile: add the e-mailed items to the customer's own cart.
            $current = $this->checkoutSession->getQuote();
            if ((int)$current->getId() !== (int)$quote->getId()) {
                $current->merge($quote);
                $current->collectTotals();
                $this->cartRepository->save($current);
            }
        } else {
            $this->checkoutSession->replaceQuote($quote);
        }

        return $this->redirect('checkout');
    }

    private function redirect(string $path, array $params = []): Redirect
    {
        return $this->redirectFactory->create()->setUrl($this->url->getUrl($path, $params) . $this->getTrackingQuery('?'));
    }

    /**
     * Campaign parameters Omnisend adds to the link, so the visit is still attributed after the redirect.
     */
    private function getTrackingQuery(string $glue): string
    {
        $keep = [];
        foreach ($this->request->getParams() as $name => $value) {
            if (is_string($value) && (str_starts_with((string)$name, 'utm_') || $name === 'omnisendContactID')) {
                $keep[$name] = $value;
            }
        }

        return $keep ? $glue . http_build_query($keep) : '';
    }
}
