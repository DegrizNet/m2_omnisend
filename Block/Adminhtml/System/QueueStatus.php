<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Block\Adminhtml\System;

use Magelan\Omnisend\Model\Queue\Queue;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Queue counts and the last errors, right in the configuration.
 */
class QueueStatus extends Field
{
    public function __construct(
        Context $context,
        private readonly Queue $queue,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $rows = [];
        foreach ($this->queue->getStats() as $type => $counts) {
            $rows[] = sprintf(
                '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                $this->escapeHtml($type),
                ($counts[Queue::STATUS_PENDING] ?? 0) + ($counts[Queue::STATUS_PROCESSING] ?? 0),
                $counts[Queue::STATUS_SENT] ?? 0,
                $counts[Queue::STATUS_FAILED] ?? 0
            );
        }
        if (!$rows) {
            return '<p>' . $this->escapeHtml(__('The queue is empty.')) . '</p>';
        }
        $html = '<table class="data-grid"><thead><tr><th>' . $this->escapeHtml(__('Type')) . '</th><th>'
            . $this->escapeHtml(__('Waiting')) . '</th><th>' . $this->escapeHtml(__('Sent')) . '</th><th>'
            . $this->escapeHtml(__('Failed')) . '</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table>';

        $errors = $this->queue->getLastErrors(5);
        if ($errors) {
            $html .= '<p><strong>' . $this->escapeHtml(__('Last errors')) . '</strong></p><ul>';
            foreach ($errors as $error) {
                $html .= '<li>' . $this->escapeHtml(sprintf('%s %s/%s %s: %s', $error['updated_at'], $error['entity_type'],
                    $error['action'], $error['object_id'], mb_substr((string)$error['last_error'], 0, 300))) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '<p class="note">' . $this->escapeHtml(__('Details and "send now": bin/magento magelan:omnisend:queue')) . '</p>';
    }
}
