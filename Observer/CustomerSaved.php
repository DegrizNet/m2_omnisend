<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Observer;

use Magelan\Omnisend\Model\Config;
use Magelan\Omnisend\Model\Payload\ContactPayload;
use Magelan\Omnisend\Model\Queue\Handler\ContactHandler;
use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * New customer accounts (from any path: registration, checkout, admin, API) and changes of name,
 * e-mail, date of birth or gender. Never touches the e-mail consent.
 */
class CustomerSaved implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly ContactPayload $contactPayload,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $customer = $observer->getEvent()->getData('customer_data_object');
            $original = $observer->getEvent()->getData('orig_customer_data_object');
            if (!$customer instanceof CustomerInterface || !$customer->getEmail()) {
                return;
            }
            $storeId = (int)$customer->getStoreId();
            if (!$this->config->isCustomerSyncEnabled($storeId)) {
                return;
            }
            if ($original instanceof CustomerInterface && !$this->hasChanged($original, $customer)) {
                return;
            }
            $this->queue->add(
                Queue::TYPE_CONTACT,
                ContactHandler::ACTION_CUSTOMER,
                (string)$customer->getEmail(),
                $storeId,
                $this->contactPayload->fromCustomer($customer)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Omnisend customer: ' . $e->getMessage());
        }
    }

    private function hasChanged(CustomerInterface $before, CustomerInterface $after): bool
    {
        foreach (['getEmail', 'getFirstname', 'getLastname', 'getDob', 'getGender'] as $getter) {
            if ((string)$before->$getter() !== (string)$after->$getter()) {
                return true;
            }
        }

        return false;
    }
}
