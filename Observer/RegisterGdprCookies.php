<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Swissup GDPR deletes cookies it does not know. The Omnisend snippet's cookies belong to the
 * marketing group, so they live only once the visitor accepts marketing cookies.
 */
class RegisterGdprCookies implements ObserverInterface
{
    private const COOKIES = [
        'omnisendContactID' => 'Omnisend: identifies a contact who came from an Omnisend e-mail or form.',
        'omnisend*' => 'Omnisend: visitor session and page views for marketing automation.',
        'soundest*' => 'Omnisend: forms and pop-ups (shown, closed, submitted).',
    ];

    public function execute(Observer $observer): void
    {
        $collection = $observer->getEvent()->getData('collection');
        if (!is_object($collection) || !method_exists($collection, 'addItemFromArray')) {
            return;
        }
        foreach (self::COOKIES as $name => $description) {
            $collection->addItemFromArray([
                'name' => $name,
                'description' => $description,
                'sort_order' => 50,
                'group' => 'marketing',
            ]);
        }
    }
}
