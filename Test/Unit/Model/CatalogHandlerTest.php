<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Test\Unit\Model;

use Magelan\Omnisend\Model\Api\ApiException;
use Magelan\Omnisend\Model\Api\Client;
use Magelan\Omnisend\Model\CatalogRegistry;
use Magelan\Omnisend\Model\Payload\CatalogPayload;
use Magelan\Omnisend\Model\Queue\Handler\CategoryHandler;
use Magelan\Omnisend\Model\Queue\Handler\ProductHandler;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Omnisend counts every 404 and 409 as a failed sync, so the first call must already be the right one.
 */
class CatalogHandlerTest extends TestCase
{
    /** @var array<int, string> "METHOD path" of every call */
    private array $calls = [];

    /**
     * @param array<string, int> $fail "METHOD path" => HTTP status to answer instead of 2xx
     */
    private function client(array $fail = []): Client
    {
        $client = $this->createMock(Client::class);
        $record = function (string $method, string $path) use ($fail): array {
            $this->calls[] = $method . ' ' . $path;
            if (isset($fail[$method . ' ' . $path])) {
                throw new ApiException('fail', $fail[$method . ' ' . $path]);
            }

            return [];
        };
        $client->method('post')->willReturnCallback(fn(string $path) => $record('POST', $path));
        $client->method('put')->willReturnCallback(fn(string $path) => $record('PUT', $path));
        $client->method('delete')->willReturnCallback(fn(string $path) => $record('DELETE', $path));
        $client->method('request')->willReturnCallback(fn(string $method, string $path) => $record($method, $path));

        return $client;
    }

    private function registry(bool $known, ?array &$added, ?array &$removed): CatalogRegistry
    {
        $added = $removed = [];
        $registry = $this->createMock(CatalogRegistry::class);
        $registry->method('has')->willReturn($known);
        $registry->method('add')->willReturnCallback(function (string $type, int $storeId, string $id) use (&$added) {
            $added[] = "$type/$storeId/$id";
        });
        $registry->method('remove')->willReturnCallback(function (string $type, int $storeId, string $id) use (&$removed) {
            $removed[] = "$type/$storeId/$id";
        });

        return $registry;
    }

    private function productHandler(Client $client, CatalogRegistry $registry, bool $visible = true): ProductHandler
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->createMock(Product::class));
        $payload = $this->createMock(CatalogPayload::class);
        $payload->method('product')->willReturn($visible ? ['id' => '7', 'title' => 'Coin'] : null);

        return new ProductHandler($client, $repository, $payload, $this->createMock(Emulation::class), $registry);
    }

    private function categoryHandler(Client $client, CatalogRegistry $registry, bool $active = true): CategoryHandler
    {
        $category = $this->createMock(Category::class);
        $category->method('getIsActive')->willReturn($active);
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->method('get')->willReturn($category);
        $payload = $this->createMock(CatalogPayload::class);
        $payload->method('category')->willReturn(['categoryID' => '5', 'title' => 'Gold']);

        return new CategoryHandler($client, $repository, $payload, $registry);
    }

    private function row(string $action = 'upsert', string $id = '7'): array
    {
        return ['store_id' => 2, 'object_id' => $id, 'action' => $action];
    }

    public static function productProvider(): array
    {
        return [
            'new product: POST only' => [false, [], ['POST products'], 'created'],
            'known product: PUT only' => [true, [], ['PUT products/7'], null],
            'new but already there (409): PUT' => [false, ['POST products' => 409], ['POST products', 'PUT products/7'], null],
            'known but removed in Omnisend (404): POST' => [true, ['PUT products/7' => 404], ['PUT products/7', 'POST products'], 'created'],
        ];
    }

    #[DataProvider('productProvider')]
    public function testProductMethod(bool $known, array $fail, array $calls, ?string $note): void
    {
        $handler = $this->productHandler($this->client($fail), $this->registry($known, $added, $removed));

        self::assertSame($note, $handler->handle($this->row()));
        self::assertSame($calls, $this->calls);
        self::assertSame(['product/2/7'], $added);
    }

    public function testProductOtherErrorIsNotRegistered(): void
    {
        $handler = $this->productHandler($this->client(['POST products' => 400]), $this->registry(false, $added, $removed));

        try {
            $handler->handle($this->row());
            self::fail('400 must reach the queue');
        } catch (ApiException $e) {
            self::assertSame(400, $e->getStatus());
        }
        self::assertSame([], $added);
    }

    public function testProductDeleteForgetsIt(): void
    {
        $handler = $this->productHandler($this->client(['DELETE products/7' => 404]), $this->registry(true, $added, $removed));

        self::assertSame('not in Omnisend', $handler->handle($this->row('delete')));
        self::assertSame(['product/2/7'], $removed);
    }

    public function testHiddenProductIsRemovedNotSent(): void
    {
        $handler = $this->productHandler($this->client(), $this->registry(true, $added, $removed), false);

        $handler->handle($this->row());
        self::assertSame(['DELETE products/7'], $this->calls);
        self::assertSame([], $added);
        self::assertSame(['product/2/7'], $removed);
    }

    public function testUnknownHiddenProductIsNotDeleted(): void
    {
        $handler = $this->productHandler($this->client(), $this->registry(false, $added, $removed), false);

        self::assertSame('not in Omnisend', $handler->handle($this->row()));
        self::assertSame([], $this->calls, 'a variant saved again must not cost a DELETE 404');
    }

    public function testUnknownInactiveCategoryIsNotDeleted(): void
    {
        $handler = $this->categoryHandler($this->client(), $this->registry(false, $added, $removed), false);

        self::assertSame('not in Omnisend', $handler->handle($this->row('upsert', '5')));
        self::assertSame([], $this->calls);
    }

    public static function categoryProvider(): array
    {
        return [
            'new category: POST only' => [false, [], ['POST product-categories']],
            'known category: PATCH only' => [true, [], ['PATCH product-categories/5']],
            'new but already there (409): PATCH' => [false, ['POST product-categories' => 409], ['POST product-categories', 'PATCH product-categories/5']],
            'known but removed in Omnisend (404): POST' => [true, ['PATCH product-categories/5' => 404], ['PATCH product-categories/5', 'POST product-categories']],
        ];
    }

    #[DataProvider('categoryProvider')]
    public function testCategoryMethod(bool $known, array $fail, array $calls): void
    {
        $handler = $this->categoryHandler($this->client($fail), $this->registry($known, $added, $removed));

        self::assertNull($handler->handle($this->row('upsert', '5')));
        self::assertSame($calls, $this->calls);
        self::assertSame(['category/2/5'], $added);
    }

    public function testInactiveCategoryIsRemoved(): void
    {
        $handler = $this->categoryHandler($this->client(), $this->registry(true, $added, $removed), false);

        $handler->handle($this->row('upsert', '5'));
        self::assertSame(['DELETE product-categories/5'], $this->calls);
        self::assertSame(['category/2/5'], $removed);
    }
}
