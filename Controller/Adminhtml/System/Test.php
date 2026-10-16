<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Controller\Adminhtml\System;

use Magelan\Omnisend\Model\BrandId;
use Magelan\Omnisend\Model\Config;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Calls GET /brands/current with the saved key of the scope shown in the configuration and fills in
 * the brand ID when it is still empty.
 */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magelan_Omnisend::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly BrandId $brandId,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): JsonResult
    {
        $storeId = $this->resolveStoreId();
        $result = $this->jsonFactory->create();
        if ($this->config->getApiKey($storeId) === '') {
            return $result->setData(['success' => false, 'message' => (string)__('No API key saved for this scope.')]);
        }
        try {
            $brand = $this->brandId->fetch($storeId);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => (string)__('Omnisend refused: %1', $e->getMessage())]);
        }
        $brandId = (string)($brand['brandID'] ?? '');
        $message = (string)__('Connected to "%1" (%2), brand ID %3.', $brand['name'] ?? '?', $brand['website'] ?? '?', $brandId ?: '?');
        if ($brandId !== '' && $this->config->getBrandId($storeId) === '') {
            $this->brandId->fillMissing([$storeId]);
            $message .= ' ' . __('Brand ID saved; reload the page to see it.');
        }
        if (!$this->config->isActive($storeId)) {
            $message .= ' ' . __('The integration is not enabled for this scope yet.');
        }

        return $result->setData(['success' => true, 'message' => $message]);
    }

    private function resolveStoreId(): int
    {
        $storeId = (int)$this->getRequest()->getParam('store');
        if ($storeId) {
            return $storeId;
        }
        $websiteId = (int)$this->getRequest()->getParam('website');
        if ($websiteId) {
            return (int)$this->storeManager->getWebsite($websiteId)->getDefaultStore()->getId();
        }

        return (int)$this->storeManager->getDefaultStoreView()?->getId();
    }
}
