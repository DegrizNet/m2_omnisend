<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Cron;

use Magelan\Omnisend\Model\Config;
use Magelan\Omnisend\Model\Queue\Queue;

class Cleanup
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Config $config
    ) {
    }

    public function execute(): void
    {
        $this->queue->cleanup($this->config->getKeepDays());
    }
}
