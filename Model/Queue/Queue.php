<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * The outbox table. Writes never throw into the storefront: a failed insert is logged and dropped.
 */
class Queue
{
    public const TABLE = 'magelan_omnisend_queue';

    public const TYPE_EVENT = 'event';
    public const TYPE_ORDER = 'order';
    public const TYPE_CONTACT = 'contact';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_CATEGORY = 'category';

    public const ACTION_UPSERT = 'upsert';
    public const ACTION_DELETE = 'delete';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** Minutes to wait before attempt 2, 3, ... */
    private const BACKOFF = [1, 5, 15, 60, 240, 720];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Adds a row, or refreshes a still pending row for the same object and action, so a product saved
     * five times in a minute is sent once with its latest state.
     *
     * @param int $delaySeconds send no earlier than this (e.g. to let tracking numbers be added to a shipment)
     * @param bool $merge false when every call is a separate event (several credit memos of one order)
     */
    public function add(
        string $type,
        string $action,
        string|int $objectId,
        int $storeId,
        ?array $payload = null,
        int $delaySeconds = 0,
        bool $merge = true
    ): void {
        try {
            $connection = $this->getConnection();
            $table = $this->getTable();
            $data = [
                'entity_type' => $type,
                'action' => $action,
                'object_id' => (string)$objectId,
                'store_id' => $storeId,
                'payload' => $payload === null ? null : $this->json->serialize($payload),
                'next_attempt_at' => $delaySeconds > 0 ? $this->later($delaySeconds) : null,
            ];
            $pending = $merge ? $connection->fetchOne(
                $connection->select()->from($table, 'queue_id')
                    ->where('entity_type = ?', $type)
                    ->where('object_id = ?', (string)$objectId)
                    ->where('store_id = ?', $storeId)
                    ->where('action = ?', $action)
                    ->where('status = ?', self::STATUS_PENDING)
                    ->where('attempts = 0')
                    ->limit(1)
            ) : false;
            if ($pending) {
                $connection->update($table, [
                    'payload' => $data['payload'],
                    'next_attempt_at' => $data['next_attempt_at'],
                ], ['queue_id = ?' => (int)$pending]);
                return;
            }
            if ($action === self::ACTION_DELETE) {
                // A pending upsert of something that is now deleted would only recreate it.
                $connection->delete($table, [
                    'entity_type = ?' => $type,
                    'object_id = ?' => (string)$objectId,
                    'store_id = ?' => $storeId,
                    'action = ?' => self::ACTION_UPSERT,
                    'status = ?' => self::STATUS_PENDING,
                ]);
            }
            $connection->insert($table, $data + ['status' => self::STATUS_PENDING]);
        } catch (\Throwable $e) {
            $this->logger->error('Omnisend queue: could not add ' . $type . '/' . $action . ' ' . $objectId
                . ': ' . $e->getMessage());
        }
    }

    /**
     * Was this already sent (or is it being sent, or did it fail for good)? Pending rows do not count:
     * add() refreshes those. $seconds limits the look-back; null means ever.
     */
    public function wasSent(string $type, string $action, string|int $objectId, int $storeId, ?int $seconds = null): bool
    {
        $connection = $this->getConnection();
        $select = $connection->select()->from($this->getTable(), 'queue_id')
            ->where('entity_type = ?', $type)
            ->where('action = ?', $action)
            ->where('object_id = ?', (string)$objectId)
            ->where('store_id = ?', $storeId)
            ->where('status <> ?', self::STATUS_PENDING)
            ->limit(1);
        if ($seconds !== null) {
            $select->where('created_at >= NOW() - INTERVAL ? SECOND', $seconds);
        }

        return (bool)$connection->fetchOne($select);
    }
    /**
     * Rows due now, oldest first, each one claimed so a second cron run cannot send it as well.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claim(int $limit): array
    {
        $connection = $this->getConnection();
        $table = $this->getTable();
        $ids = $connection->fetchCol(
            $connection->select()->from($table, 'queue_id')
                ->where('status = ?', self::STATUS_PENDING)
                ->where('next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP()')
                ->order('queue_id ASC')
                ->limit($limit)
        );
        $rows = [];
        foreach ($ids as $id) {
            $claimed = $connection->update(
                $table,
                ['status' => self::STATUS_PROCESSING],
                ['queue_id = ?' => (int)$id, 'status = ?' => self::STATUS_PENDING]
            );
            if ($claimed === 1) {
                $row = $connection->fetchRow($connection->select()->from($table)->where('queue_id = ?', (int)$id));
                $row['payload'] = $row['payload'] !== null && $row['payload'] !== ''
                    ? $this->json->unserialize($row['payload'])
                    : null;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function markSent(int $queueId, ?string $note = null): void
    {
        $this->getConnection()->update($this->getTable(), [
            'status' => self::STATUS_SENT,
            'attempts' => new \Zend_Db_Expr('attempts + 1'),
            'response_code' => 200,
            'last_error' => $note,
            'sent_at' => new \Zend_Db_Expr('UTC_TIMESTAMP()'),
            'next_attempt_at' => null,
        ], ['queue_id = ?' => $queueId]);
    }

    /**
     * Retries with a growing pause until $maxAttempts, then gives up for good.
     */
    public function markFailed(
        int $queueId,
        int $attemptsSoFar,
        string $error,
        int $status,
        bool $retryable,
        int $maxAttempts,
        ?int $retryAfterSeconds = null
    ): void {
        $attempts = $attemptsSoFar + 1;
        $giveUp = !$retryable || $attempts >= $maxAttempts;
        $delay = $retryAfterSeconds ?? 60 * self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)];
        $this->getConnection()->update($this->getTable(), [
            'status' => $giveUp ? self::STATUS_FAILED : self::STATUS_PENDING,
            'attempts' => $attempts,
            'response_code' => $status ?: null,
            'last_error' => mb_substr($error, 0, 4000),
            'next_attempt_at' => $giveUp ? null : $this->later(max(30, $delay)),
        ], ['queue_id = ?' => $queueId]);
    }

    /**
     * Puts a claimed row back untouched (e.g. the batch stopped on a rate limit).
     */
    public function release(int $queueId, int $delaySeconds = 0): void
    {
        $this->getConnection()->update($this->getTable(), [
            'status' => self::STATUS_PENDING,
            'next_attempt_at' => $delaySeconds > 0 ? $this->later($delaySeconds) : null,
        ], ['queue_id = ?' => $queueId]);
    }

    /**
     * Rows left in "processing" by a cron run that died are picked up again.
     */
    public function releaseStuck(int $minutes = 30): int
    {
        return $this->getConnection()->update(
            $this->getTable(),
            ['status' => self::STATUS_PENDING],
            [
                'status = ?' => self::STATUS_PROCESSING,
                'updated_at < NOW() - INTERVAL ? MINUTE' => $minutes,
            ]
        );
    }

    public function retryFailed(): int
    {
        return $this->getConnection()->update(
            $this->getTable(),
            ['status' => self::STATUS_PENDING, 'attempts' => 0, 'next_attempt_at' => null],
            ['status = ?' => self::STATUS_FAILED]
        );
    }

    public function cleanup(int $keepDays): int
    {
        $connection = $this->getConnection();
        return $connection->delete($this->getTable(), [
            'status IN (?)' => [self::STATUS_SENT, self::STATUS_FAILED],
            'created_at < NOW() - INTERVAL ? DAY' => $keepDays,
        ]);
    }

    /**
     * @return array<string, array<string, int>> entity type => status => count
     */
    public function getStats(): array
    {
        $connection = $this->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()->from($this->getTable(), ['entity_type', 'status', 'n' => 'COUNT(*)'])
                ->group(['entity_type', 'status'])
        );
        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['entity_type']][$row['status']] = (int)$row['n'];
        }

        return $stats;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLastErrors(int $limit = 10): array
    {
        $connection = $this->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->getTable(), ['queue_id', 'entity_type', 'action', 'object_id', 'store_id',
                    'status', 'attempts', 'response_code', 'last_error', 'updated_at'])
                ->where('last_error IS NOT NULL')
                ->where('status <> ?', self::STATUS_SENT)
                ->order('queue_id DESC')
                ->limit($limit)
        );
    }

    private function later(int $seconds): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr('UTC_TIMESTAMP() + INTERVAL ' . $seconds . ' SECOND');
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function getTable(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
