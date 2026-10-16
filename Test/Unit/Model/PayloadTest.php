<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Test\Unit\Model;

use Magelan\Omnisend\Model\Payload\ContactPayload;
use Magelan\Omnisend\Model\Payload\Event;
use Magelan\Omnisend\Model\Payload\TrackingUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PayloadTest extends TestCase
{
    private const RULES = [
        ['match' => 'gls', 'url' => 'https://gls-group.com/SI/en/parcel-tracking?match={number}'],
        ['match' => 'posta', 'url' => 'https://sledenje.posta.si/Default.aspx?tn={number}&lang=si'],
        ['match' => 'pošta', 'url' => 'https://sledenje.posta.si/Default.aspx?tn={number}&lang=si'],
    ];

    public function testTrackingUrlMatchesCodeOrTitle(): void
    {
        self::assertSame('https://gls-group.com/SI/en/parcel-tracking?match=123',
            TrackingUrl::resolve(self::RULES, 'gls', 'GLS', '123'));
        self::assertSame('https://sledenje.posta.si/Default.aspx?tn=PS%201&lang=si',
            TrackingUrl::resolve(self::RULES, 'custom', 'Pošta Slovenije', 'PS 1'));
        self::assertNull(TrackingUrl::resolve(self::RULES, 'custom', 'Lastni prevoz', '1'));
        self::assertNull(TrackingUrl::resolve(self::RULES, 'gls', 'GLS', ' '));
    }

    #[DataProvider('phoneProvider')]
    public function testE164(string $phone, string $country, ?string $expected): void
    {
        self::assertSame($expected, (new ContactPayload())->e164($phone, $country));
    }

    public static function phoneProvider(): array
    {
        return [
            'national SI' => ['041 123 456', 'SI', '+38641123456'],
            'international' => ['+386 41 123 456', 'SI', '+38641123456'],
            '00 prefix' => ['00385 91 234 5678', 'HR', '+385912345678'],
            'national unknown country' => ['0612345678', 'FR', null],
            'garbage' => ['ni telefona', 'SI', null],
            'too short' => ['+3861', 'SI', null],
        ];
    }

    public function testSubscriptionCarriesConsentOnlyWhenSubscribing(): void
    {
        $payload = new ContactPayload();
        $in = $payload->fromSubscription('a@b.si', true, 'magento-newsletter', '1.2.3.4', 'UA', ['firstName' => 'Ana']);
        $out = $payload->fromSubscription('a@b.si', false, 'magento-newsletter', '1.2.3.4', 'UA');

        self::assertSame('subscribed', $in['identifiers'][0]['channels']['email']['status']);
        self::assertSame('1.2.3.4', $in['identifiers'][0]['consent']['ip']);
        self::assertSame('Ana', $in['firstName']);
        self::assertSame('unsubscribed', $out['identifiers'][0]['channels']['email']['status']);
        self::assertArrayNotHasKey('consent', $out['identifiers'][0]);
        self::assertArrayNotHasKey('tags', $in, 'tags would replace all tags of the contact');
    }

    public function testEventEnvelope(): void
    {
        $event = Event::build(Event::PLACED_ORDER, ['email' => 'a@b.si'], ['orderID' => '1'], 'fixed-id', 0);

        self::assertSame('v2', $event['eventVersion']);
        self::assertSame('api', $event['origin']);
        self::assertSame('fixed-id', $event['eventID']);
        self::assertSame('1970-01-01T00:00:00Z', $event['eventTime']);
        self::assertSame('', Event::build(Event::ADDED_TO_CART, [], [])['eventVersion']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Event::uuid());
    }
}
