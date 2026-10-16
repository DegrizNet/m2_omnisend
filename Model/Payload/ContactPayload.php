<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Payload;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * POST /contacts bodies. Omnisend matches contacts by e-mail, so these are upserts.
 * The e-mail channel status is only sent when Magento knows it (newsletter subscribe or
 * unsubscribe); a buyer or a new account is sent without it, so an existing consent is never lost.
 * No tags either: in the dated API a tags array replaces all tags the contact already has.
 */
class ContactPayload
{
    public const STATUS_SUBSCRIBED = 'subscribed';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const STATUS_NON_SUBSCRIBED = 'nonSubscribed';

    /**
     * Newsletter subscribe or unsubscribe, with the consent record (when, from where, IP, browser).
     */
    public function fromSubscription(
        string $email,
        bool $subscribed,
        string $source,
        ?string $ip,
        ?string $userAgent,
        array $names = []
    ): array {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $identifier = [
            'type' => 'email',
            'id' => $email,
            'channels' => [
                // No status date: the field is called statusDate in v5 and statusChangedAt in the
                // dated API, and Omnisend uses the time of the request when it is missing.
                'email' => ['status' => $subscribed ? self::STATUS_SUBSCRIBED : self::STATUS_UNSUBSCRIBED],
            ],
        ];
        if ($subscribed) {
            $identifier['consent'] = [
                'source' => $source,
                'createdAt' => $now,
                'ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'userAgent' => $userAgent ? mb_substr($userAgent, 0, 200) : null,
            ];
        }

        return [
            'identifiers' => [$identifier],
            'firstName' => $this->name($names['firstName'] ?? null),
            'lastName' => $this->name($names['lastName'] ?? null),
        ];
    }

    public function fromCustomer(CustomerInterface $customer, ?string $countryCode = null): array
    {
        return [
            'identifiers' => [['type' => 'email', 'id' => (string)$customer->getEmail()]],
            'firstName' => $this->name($customer->getFirstname()),
            'lastName' => $this->name($customer->getLastname()),
            'birthdate' => $customer->getDob() ? substr((string)$customer->getDob(), 0, 10) : null,
            'gender' => match ((int)$customer->getGender()) {
                1 => 'm',
                2 => 'f',
                default => null,
            },
            'countryCode' => $countryCode,
        ];
    }

    /**
     * Buyer details from an order. A phone number is added as an identifier only in E.164 form,
     * which is what Omnisend accepts, and without an SMS status.
     */
    public function fromOrder(OrderInterface $order): array
    {
        $address = $order->getBillingAddress() ?: $order->getShippingAddress();
        $identifiers = [['type' => 'email', 'id' => (string)$order->getCustomerEmail()]];
        $phone = $address ? $this->e164((string)$address->getTelephone(), (string)$address->getCountryId()) : null;
        if ($phone) {
            $identifiers[] = ['type' => 'phone', 'id' => $phone];
        }
        $street = $address ? implode(', ', array_filter((array)$address->getStreet())) : null;

        return [
            'identifiers' => $identifiers,
            'firstName' => $this->name($order->getCustomerFirstname() ?: $address?->getFirstname()),
            'lastName' => $this->name($order->getCustomerLastname() ?: $address?->getLastname()),
            'address' => $street ? mb_substr($street, 0, 500) : null,
            'city' => $address?->getCity(),
            'postalCode' => $address?->getPostcode(),
            'countryCode' => $address?->getCountryId(),
        ];
    }

    /**
     * Best effort E.164: keeps numbers that already have a country code and adds the code for a
     * national number of the few countries this is safe for; anything else is left out.
     */
    public function e164(string $phone, string $countryId): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }
        if (!str_starts_with($digits, '+')) {
            $prefix = ['SI' => '386', 'HR' => '385', 'AT' => '43', 'DE' => '49', 'IT' => '39', 'HU' => '36',
                'RS' => '381', 'BA' => '387', 'CZ' => '420', 'SK' => '421', 'PL' => '48'][strtoupper($countryId)] ?? null;
            if (!$prefix || !str_starts_with($digits, '0')) {
                return null;
            }
            $digits = '+' . $prefix . substr($digits, 1);
        }

        return preg_match('/^\+[1-9]\d{7,13}$/', $digits) ? $digits : null;
    }

    private function name(?string $name): ?string
    {
        $name = trim((string)$name);

        return $name === '' ? null : mb_substr($name, 0, 50);
    }
}
