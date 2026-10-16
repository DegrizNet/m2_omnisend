<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Test\Unit\Model;

use Magelan\Omnisend\Model\Api\ApiException;
use Magelan\Omnisend\Model\Api\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiTest extends TestCase
{
    public function testCleanDropsNullsAndEmptyObjectsButKeepsLists(): void
    {
        $clean = Client::clean([
            'contact' => ['email' => 'a@b.si', 'id' => null],
            'properties' => [
                'note' => '',
                'tracking' => ['courierURL' => null],
                'discounts' => [],
                'lineItems' => [
                    ['productID' => '1', 'productVendor' => null, 'productCategories' => []],
                ],
                'value' => 0.0,
                'subTotalTaxIncluded' => false,
            ],
        ]);

        self::assertSame(['email' => 'a@b.si'], $clean['contact']);
        self::assertArrayNotHasKey('note', $clean['properties']);
        self::assertArrayNotHasKey('tracking', $clean['properties']);
        self::assertSame([], $clean['properties']['discounts']);
        self::assertSame([['productID' => '1', 'productCategories' => []]], $clean['properties']['lineItems']);
        self::assertSame(0.0, $clean['properties']['value']);
        self::assertFalse($clean['properties']['subTotalTaxIncluded']);
    }

    public function testListStaysAListAfterCleaning(): void
    {
        $json = json_encode(Client::clean(['images' => ['a.jpg', null, 'b.jpg']]));

        self::assertSame('{"images":["a.jpg","b.jpg"]}', $json);
    }

    #[DataProvider('retryProvider')]
    public function testRetryable(int $status, bool $retryable): void
    {
        self::assertSame($retryable, (new ApiException('x', $status))->isRetryable());
    }

    public static function retryProvider(): array
    {
        return [
            'network' => [0, true],
            'timeout' => [408, true],
            'rate limit' => [429, true],
            'server' => [503, true],
            'bad request' => [400, false],
            'forbidden' => [403, false],
            'not found' => [404, false],
            'version retired' => [410, false],
        ];
    }
}
