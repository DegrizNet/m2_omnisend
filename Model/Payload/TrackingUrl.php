<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Payload;

use Magelan\Omnisend\Model\Config;

/**
 * Tracking page for a shipment, from the "match | url" rules in the configuration. A rule matches when
 * its first part is found in the carrier code or the carrier title (case-insensitive).
 */
class TrackingUrl
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function get(string $carrierCode, string $carrierTitle, string $number, int $storeId): ?string
    {
        return self::resolve($this->config->getTrackingUrlRules($storeId), $carrierCode, $carrierTitle, $number);
    }

    /**
     * @param array<int, array{match: string, url: string}> $rules
     */
    public static function resolve(array $rules, string $carrierCode, string $carrierTitle, string $number): ?string
    {
        $number = trim($number);
        if ($number === '') {
            return null;
        }
        $haystack = mb_strtolower($carrierCode . ' ' . $carrierTitle);
        foreach ($rules as $rule) {
            if (str_contains($haystack, mb_strtolower($rule['match']))) {
                return str_replace('{number}', rawurlencode($number), $rule['url']);
            }
        }

        return null;
    }
}
