<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Payload;

/**
 * Envelope of a POST /events body. The event ID is fixed when the row is queued, so a retry of the
 * same row is the same event for Omnisend.
 */
class Event
{
    public const ADDED_TO_CART = 'added product to cart';
    public const STARTED_CHECKOUT = 'started checkout';
    public const PLACED_ORDER = 'placed order';
    public const PAID_FOR_ORDER = 'paid for order';
    public const ORDER_FULFILLED = 'order fulfilled';
    public const ORDER_CANCELED = 'order canceled';
    public const ORDER_REFUNDED = 'order refunded';

    /** Omnisend's "recommended event" versions; cart events have none. */
    private const VERSIONS = [
        self::ADDED_TO_CART => '',
        self::STARTED_CHECKOUT => '',
        self::PLACED_ORDER => 'v2',
        self::PAID_FOR_ORDER => 'v2',
        self::ORDER_FULFILLED => 'v2',
        self::ORDER_CANCELED => 'v2',
        self::ORDER_REFUNDED => 'v2',
    ];

    public const ORDER_EVENTS = [
        self::PLACED_ORDER,
        self::PAID_FOR_ORDER,
        self::ORDER_FULFILLED,
        self::ORDER_CANCELED,
        self::ORDER_REFUNDED,
    ];

    /**
     * @param array{email?: string, id?: string, phone?: string} $contact
     */
    public static function build(string $name, array $contact, array $properties, ?string $eventId = null, ?int $time = null): array
    {
        return [
            'eventName' => $name,
            'origin' => 'api',
            'eventVersion' => self::VERSIONS[$name] ?? '',
            'eventID' => $eventId ?? self::uuid(),
            'eventTime' => gmdate('Y-m-d\TH:i:s\Z', $time ?? time()),
            'contact' => $contact,
            'properties' => $properties,
        ];
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
