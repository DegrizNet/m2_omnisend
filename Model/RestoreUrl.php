<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Signed link that brings a cart back from an Omnisend e-mail (abandonedCheckoutURL), on any device.
 * The quote ID alone would let anyone open any cart, so it is signed with the store's crypt key.
 */
class RestoreUrl
{
    /** Own front name: "omnisend" is taken by other Omnisend modules that may still be installed. */
    public const PATH = 'omnisend_cart/restore';
    public const PARAM_CART = 'cart';
    public const PARAM_SIGNATURE = 'sig';

    private const SIGNATURE_LENGTH = 32;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getUrl(int $quoteId, int $storeId): string
    {
        $baseUrl = $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK);

        return $baseUrl . self::PATH . '?' . http_build_query([
            self::PARAM_CART => $quoteId,
            self::PARAM_SIGNATURE => $this->sign($quoteId),
        ]);
    }

    public function sign(int $quoteId): string
    {
        $keys = $this->getKeys();

        return $this->hash($quoteId, end($keys));
    }

    /**
     * Links signed before a key rotation stay valid, so every key in env.php is accepted.
     */
    public function isValid(int $quoteId, string $signature): bool
    {
        if ($quoteId <= 0 || strlen($signature) !== self::SIGNATURE_LENGTH) {
            return false;
        }
        foreach ($this->getKeys() as $key) {
            if (hash_equals($this->hash($quoteId, $key), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function hash(int $quoteId, string $key): string
    {
        return substr(hash_hmac('sha256', 'omnisend-cart|' . $quoteId, $key), 0, self::SIGNATURE_LENGTH);
    }

    /**
     * @return string[]
     */
    private function getKeys(): array
    {
        $raw = (string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY);
        $keys = array_values(array_filter(array_map('trim', preg_split('/\s+/', $raw) ?: [])));
        if (!$keys) {
            throw new \RuntimeException('No crypt key configured.');
        }

        return $keys;
    }
}
