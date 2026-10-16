<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Test\Unit\Model;

use Magelan\Omnisend\Model\RestoreUrl;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class RestoreUrlTest extends TestCase
{
    private function restoreUrl(string $keys): RestoreUrl
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->willReturn($keys);

        return new RestoreUrl($config, $this->createMock(StoreManagerInterface::class));
    }

    public function testSignatureIsBoundToTheCart(): void
    {
        $url = $this->restoreUrl('key-one');
        $signature = $url->sign(42);

        self::assertTrue($url->isValid(42, $signature));
        self::assertFalse($url->isValid(43, $signature));
        self::assertFalse($url->isValid(42, 'x' . substr($signature, 1)));
        self::assertFalse($url->isValid(42, ''));
        self::assertFalse($url->isValid(0, $signature));
    }

    public function testLinksSurviveKeyRotation(): void
    {
        $old = $this->restoreUrl('key-one')->sign(42);
        $rotated = $this->restoreUrl("key-one\nkey-two");

        self::assertTrue($rotated->isValid(42, $old));
        self::assertNotSame($old, $rotated->sign(42), 'new links are signed with the newest key');
    }
}
