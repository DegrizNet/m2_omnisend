<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Contact;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote;

/**
 * Who an event belongs to, for the "contact" part of an Omnisend event.
 * E-mail first (quote, then logged-in customer); otherwise the contact ID the Omnisend snippet
 * keeps in a cookie once a visitor came from an Omnisend e-mail or filled in an Omnisend form.
 */
class Identity
{
    public const COOKIE_CONTACT_ID = 'omnisendContactID';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CustomerSession $customerSession
    ) {
    }

    /**
     * @return array{email?: string, id?: string} empty when the visitor is unknown
     */
    public function forQuote(Quote $quote, ?string $email = null): array
    {
        $email = $this->validEmail($email)
            ?? $this->validEmail((string)$quote->getCustomerEmail())
            ?? $this->validEmail((string)$quote->getBillingAddress()?->getEmail())
            ?? $this->sessionEmail();
        if ($email) {
            return ['email' => $email];
        }
        $contactId = $this->getCookieContactId();

        return $contactId ? ['id' => $contactId] : [];
    }

    public function getCookieContactId(): ?string
    {
        $value = trim((string)$this->cookieManager->getCookie(self::COOKIE_CONTACT_ID));

        return preg_match('/^[A-Za-z0-9_-]{6,64}$/', $value) ? $value : null;
    }

    private function sessionEmail(): ?string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        return $this->validEmail((string)$this->customerSession->getCustomer()->getEmail());
    }

    private function validEmail(?string $email): ?string
    {
        $email = trim((string)$email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
