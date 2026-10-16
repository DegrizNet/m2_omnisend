<?php
declare(strict_types=1);

namespace Magelan\Omnisend\ViewModel;

use Magelan\Omnisend\Model\Config;
use Magelan\Omnisend\Model\Payload\ProductData;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Data for the storefront templates: the snippet (with its consent gate), "viewed product" and the
 * buyer's identification on the order success page. Templates work in Luma and Hyvä alike.
 */
class Tracking implements ArgumentInterface
{
    public const SWISSUP_CONSENT_COOKIE = 'cookie_consent';
    public const MAGENTO_CONSENT_COOKIE = 'user_allowed_save_cookie';

    public function __construct(
        private readonly Config $config,
        private readonly ProductData $productData,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ModuleManager $moduleManager,
        private readonly Registry $registry,
        private readonly CheckoutSession $checkoutSession,
        private readonly Json $json
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isSnippetEnabled($this->getStoreId()) && $this->config->getBrandId($this->getStoreId()) !== '';
    }

    public function getSnippetConfigJson(): string
    {
        $storeId = $this->getStoreId();

        return $this->json->serialize([
            'brandId' => $this->config->getBrandId($storeId),
            'consent' => $this->getConsentSource(),
            'group' => $this->config->getConsentGroup($storeId),
            'swissupCookie' => self::SWISSUP_CONSENT_COOKIE,
            'magentoCookie' => self::MAGENTO_CONSENT_COOKIE,
        ]);
    }

    /**
     * Which consent the snippet waits for: "swissup" (cookie group), "magento" (cookie notice
     * accepted) or "none" when the shop asks for no cookie consent at all.
     */
    public function getConsentSource(): string
    {
        $storeId = $this->getStoreId();
        if ($this->config->getConsentMode($storeId) === Config::CONSENT_ALWAYS) {
            return 'none';
        }
        if ($this->moduleManager->isEnabled('Swissup_Gdpr')
            && $this->scopeConfig->isSetFlag('swissup_gdpr/general/enabled', ScopeInterface::SCOPE_STORE, $storeId)
            && $this->scopeConfig->isSetFlag('swissup_gdpr/cookie_consents/enabled', ScopeInterface::SCOPE_STORE, $storeId)) {
            return 'swissup';
        }
        if ($this->scopeConfig->isSetFlag('web/cookie/cookie_restriction', ScopeInterface::SCOPE_WEBSITE)) {
            return 'magento';
        }

        return 'none';
    }

    public function isViewedProductEnabled(): bool
    {
        return $this->isEnabled() && $this->config->isViewedProductEnabled($this->getStoreId());
    }

    /**
     * "viewed product" properties (eventVersion v4). Prices are integers in cents in this event.
     */
    public function getViewedProductJson(): ?string
    {
        $product = $this->registry->registry('current_product');
        if (!$product instanceof ProductInterface || !$product->getId()) {
            return null;
        }
        /** @var Product $product */
        $storeId = $this->getStoreId();
        $price = $this->productData->getFinalPriceInclTax($product, $storeId);
        $regular = $this->productData->getRegularPriceInclTax($product, $storeId);
        if ($product->getTypeId() === 'configurable') {
            $min = (float)$product->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
            $price = $min > 0 ? $min : $price;
            $regular = 0.0;
        }
        $description = (string)$this->productData->getShortDescription($product, 2000);
        $words = preg_split('/\s+/u', $description) ?: [];

        $data = [
            'id' => (string)$product->getId(),
            'currency' => (string)$this->storeManager->getStore()->getCurrentCurrencyCode(),
            'price' => (int)round($price * 100),
            'title' => (string)$product->getName(),
            'description' => implode(' ', array_slice($words, 0, 30)),
            'imageUrl' => $this->productData->getImageUrl($product, $storeId),
            'url' => (string)$this->productData->getUrl($product, $storeId),
            'status' => $this->productData->getStockStatus($product),
            'categories' => $this->productData->getCategories($product, $storeId),
        ];
        if ($regular > $price + 0.005) {
            $data['oldPrice'] = (int)round($regular * 100);
        }

        return $this->json->serialize(array_filter($data, fn($value) => $value !== null && $value !== ''));
    }

    /**
     * On the success page: who just ordered, so the visitor is known to the snippet from now on.
     */
    public function getIdentifyJson(): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $order = $this->checkoutSession->getLastRealOrder();
        $email = $order ? (string)$order->getCustomerEmail() : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $this->json->serialize(['email' => $email]);
    }

    private function getStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }
}
