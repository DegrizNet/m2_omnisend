<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Test\Unit\Model;

use Magelan\Omnisend\Model\Api\ApiException;
use Magelan\Omnisend\Model\Config;
use Magelan\Omnisend\Model\Queue\HandlerInterface;
use Magelan\Omnisend\Model\Queue\Processor;
use Magelan\Omnisend\Model\Queue\Queue;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ProcessorTest extends TestCase
{
    private function row(int $id, int $attempts = 0): array
    {
        return ['queue_id' => $id, 'entity_type' => 'event', 'action' => 'x', 'object_id' => '1',
            'store_id' => 1, 'payload' => [], 'attempts' => $attempts];
    }

    private function config(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isActive')->willReturn(true);
        $config->method('getBatchSize')->willReturn(10);
        $config->method('getMaxAttempts')->willReturn(3);

        return $config;
    }

    public function testSentRetriedAndGivenUp(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('claim')->willReturn([$this->row(1), $this->row(2), $this->row(3, 2), $this->row(4)]);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function (array $row) {
            return match ((int)$row['queue_id']) {
                1 => null,
                2 => throw new ApiException('server', 503),
                3 => throw new ApiException('server', 503),
                4 => throw new ApiException('bad', 400),
            };
        });

        $queue->expects(self::once())->method('markSent')->with(1, null);
        $failed = [];
        $queue->method('markFailed')->willReturnCallback(
            function (int $id, int $attempts, string $error, int $status, bool $retryable) use (&$failed): void {
                $failed[$id] = [$attempts, $status, $retryable];
            }
        );

        $result = (new Processor($queue, $this->config(), new NullLogger(), ['event' => $handler]))->process();

        self::assertSame(['sent' => 1, 'failed' => 2, 'retried' => 1], $result);
        self::assertSame([0, 503, true], $failed[2]);
        self::assertSame([2, 503, true], $failed[3], 'third attempt of three: queue gives up');
        self::assertSame([0, 400, false], $failed[4]);
    }

    public function testRateLimitStopsTheRunAndKeepsTheRest(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('claim')->willReturn([$this->row(1), $this->row(2), $this->row(3)]);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willThrowException(new ApiException('slow down', 429, '', 30));

        $released = [];
        $queue->method('release')->willReturnCallback(function (int $id, int $delay) use (&$released): void {
            $released[$id] = $delay;
        });
        $queue->expects(self::once())->method('markFailed');

        (new Processor($queue, $this->config(), new NullLogger(), ['event' => $handler]))->process();

        self::assertSame([2 => 30, 3 => 30], $released);
    }

    public function testInactiveStoreIsPostponedNotLost(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('claim')->willReturn([$this->row(1)]);
        $config = $this->createMock(Config::class);
        $config->method('isActive')->willReturn(false);
        $config->method('getMaxAttempts')->willReturn(3);
        $handler = $this->createMock(HandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $queue->expects(self::once())->method('release')->with(1, 3600);

        (new Processor($queue, $config, new NullLogger(), ['event' => $handler]))->process(10);
    }
}
