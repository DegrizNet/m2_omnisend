<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Config\Source;

use Magelan\Omnisend\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class ConsentMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::CONSENT_COOKIE, 'label' => __('After cookie consent')],
            ['value' => Config::CONSENT_ALWAYS, 'label' => __('Always')],
        ];
    }
}
