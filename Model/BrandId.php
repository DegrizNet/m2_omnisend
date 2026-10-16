<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model;

use Magelan\Omnisend\Model\Api\Client;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * The web snippet needs the brand ID, which the shop owner rarely knows. It is read once from
 * GET /brands/current and saved for the store view, so the storefront never waits for Omnisend.
 */
class BrandId
{
    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $appConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{brandID?: string, name?: string, website?: string, currency?: string} the brand behind the key
     */
    public function fetch(int $storeId): array
    {
        return $this->client->get('brands/current', $storeId);
    }

    /**
     * Saves the brand ID for every active store view that has none yet. Returns how many were saved.
     *
     * @param int[] $storeIds
     */
    public function fillMissing(array $storeIds): int
    {
        $saved = 0;
        $fetched = [];
        foreach ($storeIds as $storeId) {
            if (!$this->config->isSnippetEnabled($storeId) || $this->config->getBrandId($storeId) !== ''
                || $this->config->getApiKey($storeId) === '') {
                continue;
            }
            $apiKey = $this->config->getApiKey($storeId);
            $failKey = 'magelan_omnisend_brand_failed_' . sha1($apiKey);
            if (!isset($fetched[$apiKey]) && $this->cache->load($failKey)) {
                // Failed recently; do not ask Omnisend again every minute.
                $fetched[$apiKey] = '';
            }
            try {
                $fetched[$apiKey] ??= (string)($this->fetch($storeId)['brandID'] ?? '');
            } catch (\Throwable $e) {
                $this->logger->warning('Omnisend: brand ID not available for store ' . $storeId . ': ' . $e->getMessage());
                $fetched[$apiKey] = '';
                $this->cache->save('1', $failKey, [], 6 * 3600);
            }
            if ($fetched[$apiKey] !== '') {
                $this->save($storeId, $fetched[$apiKey]);
                $saved++;
            }
        }
        if ($saved) {
            $this->appConfig->reinit();
            // Cached pages were rendered without the snippet.
            $this->cacheTypeList->cleanType('full_page');
        }

        return $saved;
    }

    public function save(int $storeId, string $brandId): void
    {
        $this->configWriter->save(Config::XML_BRAND_ID, $brandId, ScopeInterface::SCOPE_STORES, $storeId);
    }
}
