<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Block\Adminhtml\System;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Test connection" in the configuration: asks Omnisend which brand the saved API key belongs to.
 */
class TestButton extends Field
{
    protected $_template = 'Magelan_Omnisend::system/test-button.phtml';

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getTestUrl(): string
    {
        return $this->getUrl('magelan_omnisend/system/test', [
            'store' => (int)$this->getRequest()->getParam('store'),
            'website' => (int)$this->getRequest()->getParam('website'),
        ]);
    }
}
