<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Console\Command;

use Magelan\Omnisend\Model\CatalogQueue;
use Magelan\Omnisend\Model\Config;
use Magelan\Omnisend\Model\Payload\ContactPayload;
use Magelan\Omnisend\Model\Queue\Handler\ContactHandler;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Framework\App\ResourceConnection;
use Magento\Newsletter\Model\Subscriber;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * First import (or a full refresh) of the catalog and the newsletter list. Everything is queued;
 * cron sends it, within Omnisend's rate limit.
 */
class Sync extends Command
{
    private const WHAT = ['categories', 'products', 'subscribers'];

    public function __construct(
        private readonly CatalogQueue $catalogQueue,
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly ContactPayload $contactPayload,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('magelan:omnisend:sync')
            ->setDescription('Queue the whole catalog or newsletter list for Omnisend (categories, products, subscribers)')
            ->addArgument('what', InputArgument::IS_ARRAY, implode(', ', self::WHAT) . ' (default: categories products)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $what = (array)$input->getArgument('what') ?: ['categories', 'products'];
        $unknown = array_diff($what, self::WHAT);
        if ($unknown) {
            $output->writeln('<error>Unknown: ' . implode(', ', $unknown) . '</error>');
            return Command::FAILURE;
        }
        $connection = $this->resource->getConnection();

        if (in_array('categories', $what, true)) {
            $ids = $connection->fetchCol($connection->select()
                ->from($this->resource->getTableName('catalog_category_entity'), 'entity_id')
                ->where('level >= 2'));
            $this->catalogQueue->categories(array_map('intval', $ids));
            $output->writeln(sprintf('Categories queued: %d', count($ids)));
        }

        if (in_array('products', $what, true)) {
            $ids = $connection->fetchCol($connection->select()
                ->from($this->resource->getTableName('catalog_product_entity'), 'entity_id'));
            foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
                $this->catalogQueue->products($chunk);
            }
            $output->writeln(sprintf('Products queued: %d (variants are sent inside their configurable product)', count($ids)));
        }

        if (in_array('subscribers', $what, true)) {
            $rows = $connection->fetchAll($connection->select()
                ->from($this->resource->getTableName('newsletter_subscriber'), ['subscriber_email', 'store_id', 'change_status_at'])
                ->where('subscriber_status = ?', Subscriber::STATUS_SUBSCRIBED));
            $count = 0;
            foreach ($rows as $row) {
                $storeId = (int)$row['store_id'];
                if (!$this->config->isNewsletterSyncEnabled($storeId)) {
                    continue;
                }
                $payload = $this->contactPayload->fromSubscription((string)$row['subscriber_email'], true,
                    'magento-import', null, null);
                $this->queue->add(Queue::TYPE_CONTACT, ContactHandler::ACTION_SUBSCRIPTION,
                    (string)$row['subscriber_email'], $storeId, $payload);
                $count++;
            }
            $output->writeln(sprintf('Subscribers queued: %d', $count));
        }

        $output->writeln('Cron sends the queue; follow it with: bin/magento magelan:omnisend:queue');

        return Command::SUCCESS;
    }
}
