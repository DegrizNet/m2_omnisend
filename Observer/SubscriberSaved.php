<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Observer;

use Magelan\Omnisend\Model\Config;
use Magelan\Omnisend\Model\Payload\ContactPayload;
use Magelan\Omnisend\Model\Queue\Handler\ContactHandler;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Newsletter\Model\Subscriber;
use Psr\Log\LoggerInterface;

/**
 * Newsletter consent to Omnisend: subscribed (only once confirmed, when Magento asks for confirmation)
 * and unsubscribed. The consent record keeps the time, IP and browser of the request that gave it.
 */
class SubscriberSaved implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly ContactPayload $contactPayload,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly RemoteAddress $remoteAddress,
        private readonly Header $httpHeader,
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $subscriber = $observer->getEvent()->getData('subscriber');
            if (!$subscriber instanceof Subscriber || !$subscriber->getSubscriberEmail()) {
                return;
            }
            $status = (int)$subscriber->getSubscriberStatus();
            if ((int)$subscriber->getOrigData('subscriber_status') === $status && $subscriber->getOrigData('subscriber_id')) {
                return;
            }
            $subscribed = $status === Subscriber::STATUS_SUBSCRIBED;
            if (!$subscribed && $status !== Subscriber::STATUS_UNSUBSCRIBED) {
                // Waiting for confirmation or not activated: nothing to tell Omnisend yet.
                return;
            }
            $storeId = (int)$subscriber->getStoreId();
            if (!$this->config->isNewsletterSyncEnabled($storeId)) {
                return;
            }

            $isVisitor = in_array($this->getArea(), [Area::AREA_FRONTEND, Area::AREA_WEBAPI_REST], true);
            $email = (string)$subscriber->getSubscriberEmail();
            $payload = $this->contactPayload->fromSubscription(
                $email,
                $subscribed,
                $isVisitor ? 'magento-newsletter' : 'magento-admin',
                $isVisitor ? (string)$this->remoteAddress->getRemoteAddress() : null,
                $isVisitor ? (string)$this->httpHeader->getHttpUserAgent() : null,
                $this->getNames((int)$subscriber->getCustomerId())
            );
            $this->queue->add(Queue::TYPE_CONTACT, ContactHandler::ACTION_SUBSCRIPTION, $email, $storeId, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('Omnisend newsletter: ' . $e->getMessage());
        }
    }

    /**
     * @return array{firstName?: string, lastName?: string}
     */
    private function getNames(int $customerId): array
    {
        if (!$customerId) {
            return [];
        }
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Exception $e) {
            return [];
        }

        return ['firstName' => (string)$customer->getFirstname(), 'lastName' => (string)$customer->getLastname()];
    }

    private function getArea(): string
    {
        try {
            return $this->appState->getAreaCode();
        } catch (\Exception $e) {
            return '';
        }
    }
}
