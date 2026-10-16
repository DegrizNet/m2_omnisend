<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    public const XML_ENABLED = 'magelan_omnisend/general/enabled';
    public const XML_API_KEY = 'magelan_omnisend/general/api_key';
    public const XML_BRAND_ID = 'magelan_omnisend/general/brand_id';
    public const XML_API_URL = 'magelan_omnisend/general/api_url';
    public const XML_API_VERSION = 'magelan_omnisend/general/api_version';
    public const XML_DEBUG = 'magelan_omnisend/general/debug';

    public const XML_SNIPPET = 'magelan_omnisend/tracking/snippet';
    public const XML_CONSENT_MODE = 'magelan_omnisend/tracking/consent_mode';
    public const XML_CONSENT_GROUP = 'magelan_omnisend/tracking/consent_group';
    public const XML_VIEWED_PRODUCT = 'magelan_omnisend/tracking/viewed_product';

    public const XML_EVENT_CART = 'magelan_omnisend/events/added_to_cart';
    public const XML_EVENT_CHECKOUT = 'magelan_omnisend/events/started_checkout';
    public const XML_EVENT_ORDER = 'magelan_omnisend/events/orders';
    public const XML_TRACKING_URLS = 'magelan_omnisend/events/tracking_urls';

    public const XML_CONTACT_NEWSLETTER = 'magelan_omnisend/contacts/newsletter';
    public const XML_CONTACT_CUSTOMERS = 'magelan_omnisend/contacts/customers';
    public const XML_CONTACT_ORDERS = 'magelan_omnisend/contacts/orders';

    public const XML_CATALOG_PRODUCTS = 'magelan_omnisend/catalog/products';
    public const XML_CATALOG_CATEGORIES = 'magelan_omnisend/catalog/categories';

    public const XML_QUEUE_BATCH = 'magelan_omnisend/queue/batch_size';
    public const XML_QUEUE_ATTEMPTS = 'magelan_omnisend/queue/max_attempts';
    public const XML_QUEUE_KEEP_DAYS = 'magelan_omnisend/queue/keep_days';

    public const CONSENT_ALWAYS = 'always';
    public const CONSENT_COOKIE = 'cookie';

    public const API_VERSION_CURRENT = '2026-03-15';
    public const API_VERSION_V5 = 'v5';
    public const DEFAULT_API_URL = 'https://api.omnisend.com';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Enabled and has an API key: nothing is queued or sent for a store without both.
     */
    public function isActive(?int $storeId = null): bool
    {
        return $this->flag(self::XML_ENABLED, $storeId) && $this->getApiKey($storeId) !== '';
    }

    public function getApiKey(?int $storeId = null): string
    {
        // Decrypted by Magento already: config.xml declares the Encrypted backend model for this path.
        return trim((string)$this->value(self::XML_API_KEY, $storeId));
    }

    /**
     * Brand ID for the web snippet. Filled in from GET /brands/current by the connection test or
     * the first cron run when left empty (see BrandId).
     */
    public function getBrandId(?int $storeId = null): string
    {
        return trim((string)$this->value(self::XML_BRAND_ID, $storeId));
    }

    /**
     * Dated API version ("2026-03-15", sent as the Omnisend-Version header) or the legacy "v5"
     * (sunset by Omnisend on 2027-06-30). Payloads are the same; path and authentication differ.
     */
    public function getApiVersion(): string
    {
        $version = trim((string)$this->scopeConfig->getValue(self::XML_API_VERSION));

        return $version !== '' ? $version : self::API_VERSION_CURRENT;
    }

    public function isLegacyApi(): bool
    {
        return $this->getApiVersion() === self::API_VERSION_V5;
    }

    /**
     * Base URL including the version path: https://api.omnisend.com/api or .../v5.
     */
    public function getApiUrl(): string
    {
        $url = rtrim(trim((string)$this->scopeConfig->getValue(self::XML_API_URL)) ?: self::DEFAULT_API_URL, '/');
        if (preg_match('#/(api|v\d+)$#', $url)) {
            return $url;
        }

        return $url . ($this->isLegacyApi() ? '/v5' : '/api');
    }

    public function isDebug(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_DEBUG);
    }

    /**
     * The snippet needs only the brand ID, so a shop can start with Omnisend forms and page views
     * before it has an API key; everything server-side waits for the key (isActive).
     */
    public function isSnippetEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_ENABLED, $storeId) && $this->flag(self::XML_SNIPPET, $storeId);
    }

    public function isViewedProductEnabled(?int $storeId = null): bool
    {
        return $this->isSnippetEnabled($storeId) && $this->flag(self::XML_VIEWED_PRODUCT, $storeId);
    }

    public function getConsentMode(?int $storeId = null): string
    {
        $mode = (string)$this->value(self::XML_CONSENT_MODE, $storeId);

        return $mode === self::CONSENT_ALWAYS ? self::CONSENT_ALWAYS : self::CONSENT_COOKIE;
    }

    public function getConsentGroup(?int $storeId = null): string
    {
        return trim((string)$this->value(self::XML_CONSENT_GROUP, $storeId)) ?: 'marketing';
    }

    public function isCartEventEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->flag(self::XML_EVENT_CART, $storeId);
    }

    public function isCheckoutEventEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->flag(self::XML_EVENT_CHECKOUT, $storeId);
    }

    public function isOrderEventEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->flag(self::XML_EVENT_ORDER, $storeId);
    }

    /**
     * @return array<int, array{match: string, url: string}> one rule per line: "match | url with {number}"
     */
    public function getTrackingUrlRules(?int $storeId = null): array
    {
        $rules = [];
        foreach (preg_split('/\R/', (string)$this->value(self::XML_TRACKING_URLS, $storeId)) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' && !str_starts_with($parts[0], '#')) {
                $rules[] = ['match' => $parts[0], 'url' => $parts[1]];
            }
        }

        return $rules;
    }

    public function isNewsletterSyncEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->flag(self::XML_CONTACT_NEWSLETTER, $storeId);
    }

    public function isCustomerSyncEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->flag(self::XML_CONTACT_CUSTOMERS, $storeId);
    }

    public function isOrderContactSyncEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->flag(self::XML_CONTACT_ORDERS, $storeId);
    }

    public function isProductSyncEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->flag(self::XML_CATALOG_PRODUCTS, $storeId);
    }

    public function isCategorySyncEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->flag(self::XML_CATALOG_CATEGORIES, $storeId);
    }

    public function getBatchSize(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_QUEUE_BATCH) ?: 100);
    }

    public function getMaxAttempts(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_QUEUE_ATTEMPTS) ?: 6);
    }

    public function getKeepDays(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_QUEUE_KEEP_DAYS) ?: 30);
    }

    /**
     * One Omnisend account (API key) has one product catalog. When several store views share a key,
     * the catalog is written from one of them: the default store view of its website if that one uses
     * the key, otherwise the first store view with it.
     *
     * @return int[] store IDs that own a catalog, one per distinct API key
     */
    public function getCatalogStoreIds(): array
    {
        $byKey = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if (!$this->isActive($storeId)) {
                continue;
            }
            $key = $this->getApiKey($storeId);
            $isDefault = (int)$store->getWebsite()->getDefaultStore()->getId() === $storeId;
            if (!isset($byKey[$key]) || ($isDefault && !$byKey[$key]['default'])) {
                $byKey[$key] = ['store' => $storeId, 'default' => $isDefault];
            }
        }

        return array_values(array_map(fn(array $row) => $row['store'], $byKey));
    }

    public function isCatalogStore(int $storeId): bool
    {
        return in_array($storeId, $this->getCatalogStoreIds(), true);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
