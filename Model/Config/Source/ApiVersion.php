<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Config\Source;

use Magelan\Omnisend\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class ApiVersion implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::API_VERSION_CURRENT, 'label' => Config::API_VERSION_CURRENT],
            ['value' => Config::API_VERSION_V5, 'label' => __('v5 (legacy, retired 30 June 2027)')],
        ];
    }
}
