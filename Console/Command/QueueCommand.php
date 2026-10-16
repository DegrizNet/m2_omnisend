<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Console\Command;

use Magelan\Omnisend\Model\Queue\Processor;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueCommand extends Command
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Processor $processor,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('magelan:omnisend:queue')
            ->setDescription('Omnisend queue: counts and last errors; send now; retry failed rows')
            ->addOption('process', 'p', InputOption::VALUE_OPTIONAL, 'Send up to N due rows now', false)
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Put failed rows back in the queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('retry-failed')) {
            $output->writeln(sprintf('Failed rows back in the queue: %d', $this->queue->retryFailed()));
        }
        $process = $input->getOption('process');
        if ($process !== false) {
            try {
                $this->appState->setAreaCode(Area::AREA_CRONTAB);
            } catch (\Exception $e) {
                // Area already set.
            }
            $result = $this->processor->process($process !== null ? max(1, (int)$process) : null, 600);
            $output->writeln(sprintf('Sent %d, will retry %d, failed %d', $result['sent'], $result['retried'], $result['failed']));
        }

        $table = new Table($output);
        $table->setHeaders(['Type', 'pending', 'processing', 'sent', 'failed']);
        foreach ($this->queue->getStats() as $type => $counts) {
            $table->addRow([$type, $counts['pending'] ?? 0, $counts['processing'] ?? 0, $counts['sent'] ?? 0, $counts['failed'] ?? 0]);
        }
        $table->render();

        $errors = $this->queue->getLastErrors();
        if ($errors) {
            $output->writeln('');
            $output->writeln('Last errors:');
            foreach ($errors as $row) {
                $output->writeln(sprintf('  #%d %s/%s %s (store %d, %s, %d attempts): %s',
                    $row['queue_id'], $row['entity_type'], $row['action'], $row['object_id'], $row['store_id'],
                    $row['status'], $row['attempts'], mb_substr((string)$row['last_error'], 0, 300)));
            }
        }

        return Command::SUCCESS;
    }
}
