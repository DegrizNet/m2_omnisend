<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Queue;

use Magelan\Omnisend\Model\Api\ApiException;
use Magelan\Omnisend\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Sends due queue rows. Runs from cron every minute and from bin/magento magelan:omnisend:queue --process.
 */
class Processor
{
    /** Seconds one run may take, so runs do not pile up behind each other. */
    private const TIME_BUDGET = 50;

    /**
     * @param array<string, HandlerInterface> $handlers keyed by queue entity type
     */
    public function __construct(
        private readonly Queue $queue,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly array $handlers = []
    ) {
    }

    /**
     * @return array{sent: int, failed: int, retried: int}
     */
    public function process(?int $limit = null, int $timeBudget = self::TIME_BUDGET): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'retried' => 0];
        $this->queue->releaseStuck();
        $rows = $this->queue->claim($limit ?? $this->config->getBatchSize());
        $started = microtime(true);
        $maxAttempts = $this->config->getMaxAttempts();

        foreach ($rows as $index => $row) {
            $queueId = (int)$row['queue_id'];
            if (microtime(true) - $started > $timeBudget) {
                $this->releaseFrom($rows, $index);
                break;
            }
            $handler = $this->handlers[$row['entity_type']] ?? null;
            if (!$handler) {
                $this->queue->markFailed($queueId, (int)$row['attempts'], 'No handler for ' . $row['entity_type'],
                    0, false, $maxAttempts);
                $result['failed']++;
                continue;
            }
            if (!$this->config->isActive((int)$row['store_id'])) {
                // Switched off after the row was queued: keep it, it is sent when switched on again.
                $this->queue->release($queueId, 3600);
                continue;
            }

            try {
                $note = $handler->handle($row);
                $this->queue->markSent($queueId, $note);
                $result['sent']++;
            } catch (ApiException $e) {
                $this->queue->markFailed($queueId, (int)$row['attempts'], $e->getMessage(), $e->getStatus(),
                    $e->isRetryable(), $maxAttempts, $e->getRetryAfter());
                $e->isRetryable() && (int)$row['attempts'] + 1 < $maxAttempts
                    ? $result['retried']++
                    : $result['failed']++;
                if ($e->isRateLimited()) {
                    // Everything else would hit the same limit; continue on the next run.
                    $this->releaseFrom($rows, $index + 1, $e->getRetryAfter() ?? 60);
                    break;
                }
            } catch (\Throwable $e) {
                // A bug or missing data on our side: retrying the same row will not help.
                $this->logger->error('Omnisend queue row ' . $queueId . ': ' . $e->getMessage(), ['exception' => $e]);
                $this->queue->markFailed($queueId, (int)$row['attempts'], get_class($e) . ': ' . $e->getMessage(),
                    0, false, $maxAttempts);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function releaseFrom(array $rows, int $from, int $delay = 0): void
    {
        foreach (array_slice($rows, $from) as $row) {
            $this->queue->release((int)$row['queue_id'], $delay);
        }
    }
}
