<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Cron;

use Magelan\Omnisend\Model\BrandId;
use Magelan\Omnisend\Model\Queue\Processor;
use Magento\Store\Model\StoreManagerInterface;

class ProcessQueue
{
    public function __construct(
        private readonly Processor $processor,
        private readonly BrandId $brandId,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): void
    {
        $this->brandId->fillMissing(array_map('intval', array_keys($this->storeManager->getStores())));
        $this->processor->process();
    }
}