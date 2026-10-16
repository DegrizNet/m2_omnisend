<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model;

use Magelan\Omnisend\Model\Contact\Identity;
use Magelan\Omnisend\Model\Payload\CartPayload;
use Magelan\Omnisend\Model\Payload\Event;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Psr\Log\LoggerInterface;

/**
 * Queues the two cart events. Both need a known visitor (e-mail or Omnisend contact ID);
 * Omnisend discards cart events of anonymous visitors anyway.
 */
class CartEvents
{
    /** "started checkout" again for the same cart only after this pause (reloads, back and forth). */
    private const CHECKOUT_REPEAT_AFTER = 1800;
    private const EMAIL_SETTLE = 120;

    public function __construct(
        private readonly Config $config,
        private readonly Identity $identity,
        private readonly CartPayload $cartPayload,
        private readonly Queue $queue,
        private readonly LoggerInterface $logger
    ) {
    }

    public function addedToCart(QuoteItem $item): void
    {
        try {
            $quote = $item->getQuote();
            if (!$quote || !$quote->getId() || !$this->config->isCartEventEnabled((int)$quote->getStoreId())) {
                return;
            }
            $contact = $this->identity->forQuote($quote);
            if (!$contact) {
                return;
            }
            $properties = $this->cartPayload->properties($quote, (int)$item->getId());
            if ($properties === null) {
                return;
            }
            $this->queue->add(
                Queue::TYPE_EVENT,
                Event::ADDED_TO_CART,
                (int)$quote->getId(),
                (int)$quote->getStoreId(),
                Event::build(Event::ADDED_TO_CART, $contact, $properties)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Omnisend added to cart: ' . $e->getMessage());
        }
    }

    /**
     * @param string|null $email typed at checkout, before it is saved on the quote
     */
    public function startedCheckout(Quote $quote, ?string $email = null): void
    {
        try {
            $storeId = (int)$quote->getStoreId();
            if (!$quote->getId() || !$quote->getItemsCount() || !$this->config->isCheckoutEventEnabled($storeId)) {
                return;
            }
            $contact = $this->identity->forQuote($quote, $email);
            if (!$contact) {
                return;
            }
            $quoteId = (int)$quote->getId();
            if ($this->queue->wasSent(Queue::TYPE_EVENT, Event::STARTED_CHECKOUT, $quoteId, $storeId,
                self::CHECKOUT_REPEAT_AFTER)) {
                return;
            }
            $properties = $this->cartPayload->properties($quote);
            if ($properties === null) {
                return;
            }
            $this->queue->add(
                Queue::TYPE_EVENT,
                Event::STARTED_CHECKOUT,
                $quoteId,
                $storeId,
                Event::build(Event::STARTED_CHECKOUT, $contact, $properties),
                // The checkout asks about the e-mail while it is still being typed; wait for the final one.
                $email !== null ? self::EMAIL_SETTLE : 0
            );
        } catch (\Throwable $e) {
            $this->logger->error('Omnisend started checkout: ' . $e->getMessage());
        }
    }
}
