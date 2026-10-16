<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Payload;

use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Properties of the order events (eventVersion v2). Built when the row is sent, so every event
 * carries the order as it is at that moment: payment and fulfilment state, tracking, refunds.
 */
class OrderPayload
{
    public function __construct(
        private readonly LineItemBuilder $lineItemBuilder,
        private readonly TrackingUrl $trackingUrl,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array{email?: string, firstName?: string, lastName?: string}
     */
    public function contact(OrderInterface $order): array
    {
        $address = $order->getBillingAddress();

        return array_filter([
            'email' => (string)$order->getCustomerEmail(),
            'firstName' => mb_substr((string)($order->getCustomerFirstname() ?: $address?->getFirstname()), 0, 50),
            'lastName' => mb_substr((string)($order->getCustomerLastname() ?: $address?->getLastname()), 0, 50),
        ], fn($value) => $value !== '');
    }

    public function properties(OrderInterface $order, string $eventName, ?Creditmemo $creditmemo = null): array
    {
        /** @var Order $order */
        $lineItems = array_map(function (array $line): array {
            // Not part of the v2 order events.
            unset($line['productStrikeThroughPrice']);
            $line['productTotalPrice'] = round($line['productPrice'] * $line['productQuantity'], 2);
            return $line;
        }, $this->lineItemBuilder->buildForOrder($order));

        $properties = [
            'orderID' => (string)$order->getEntityId(),
            'orderNumber' => (string)$order->getIncrementId(),
            'orderStatusURL' => $this->getStatusUrl($order),
            'createdAt' => $this->isoDate((string)$order->getCreatedAt()),
            'currency' => (string)$order->getOrderCurrencyCode(),
            'totalPrice' => round((float)$order->getGrandTotal(), 2),
            'subTotalPrice' => round((float)$order->getSubtotalInclTax(), 2),
            'subTotalTaxIncluded' => true,
            'totalTax' => round((float)$order->getTaxAmount(), 2),
            'totalDiscount' => round(abs((float)$order->getDiscountAmount()), 2),
            'shippingPrice' => round((float)($order->getShippingInclTax() ?: $order->getShippingAmount()), 2),
            'shippingMethod' => (string)$order->getShippingDescription(),
            'discounts' => $this->getDiscounts($order),
            'paymentMethod' => $this->getPaymentTitle($order),
            'paymentStatus' => $this->getPaymentStatusFor($order, $eventName),
            'fulfillmentStatus' => $this->getFulfillmentStatus($order),
            'note' => (string)$order->getCustomerNote(),
            'billingAddress' => $this->address($order->getBillingAddress()),
            'shippingAddress' => $this->address($order->getShippingAddress()),
            'lineItems' => $lineItems,
        ];

        $tracking = $this->getTracking($order, $eventName !== Event::PLACED_ORDER);
        if ($tracking) {
            $properties['tracking'] = $tracking;
        }

        if ($eventName === Event::ORDER_REFUNDED) {
            $properties += $this->getRefund($order, $creditmemo);
        }

        return $properties;
    }

    /**
     * The order is read when the event is sent, possibly after later changes; the event itself
     * still says what happened (a "paid for order" is never "awaitingPayment").
     */
    private function getPaymentStatusFor(Order $order, string $eventName): string
    {
        $status = $this->getPaymentStatus($order);
        if ($eventName === Event::PAID_FOR_ORDER && in_array($status, ['awaitingPayment', 'voided'], true)) {
            return 'paid';
        }
        if ($eventName === Event::ORDER_CANCELED && $status === 'awaitingPayment') {
            return 'voided';
        }

        return $status;
    }

    public function getPaymentStatus(Order $order): string
    {
        $grandTotal = (float)$order->getGrandTotal();
        $paid = (float)$order->getTotalPaid();
        $refunded = (float)$order->getTotalRefunded();

        if ($refunded > 0) {
            return $refunded + 0.005 >= $paid ? 'refunded' : 'partiallyRefunded';
        }
        if ($order->getState() === Order::STATE_CANCELED) {
            return 'voided';
        }
        if ($paid > 0 || ($grandTotal <= 0.005 && $order->getState() !== Order::STATE_NEW)) {
            return $paid + 0.005 >= $grandTotal ? 'paid' : 'partiallyPaid';
        }

        return 'awaitingPayment';
    }

    public function getFulfillmentStatus(Order $order): string
    {
        $ordered = 0.0;
        $shipped = 0.0;
        foreach ($order->getAllItems() as $item) {
            if ($item->getIsVirtual() || $item->getParentItem()) {
                continue;
            }
            $ordered += (float)$item->getQtyOrdered() - (float)$item->getQtyCanceled();
            $shipped += (float)$item->getQtyShipped();
        }
        if ($shipped <= 0) {
            return $order->getState() === Order::STATE_COMPLETE ? 'fulfilled' : 'unfulfilled';
        }

        return $shipped + 0.0001 >= $ordered ? 'fulfilled' : 'inProgress';
    }

    /**
     * @return array<string, string>|null
     */
    private function getTracking(Order $order, bool $withCode): ?array
    {
        $tracks = $order->getTracksCollection()->getItems();
        $track = end($tracks);
        if (!$track) {
            return null;
        }
        $number = (string)$track->getTrackNumber();
        $tracking = array_filter([
            'courierTitle' => (string)$track->getTitle(),
            'courierURL' => (string)$this->trackingUrl->get(
                (string)$track->getCarrierCode(),
                (string)$track->getTitle(),
                $number,
                (int)$order->getStoreId()
            ),
        ]);
        if ($withCode && $number !== '') {
            $tracking['code'] = $number;
        }

        return $tracking ?: null;
    }

    private function getRefund(Order $order, ?Creditmemo $creditmemo): array
    {
        if (!$creditmemo) {
            return [
                'totalRefundedAmount' => round((float)$order->getTotalRefunded(), 2),
                'totalRefundedTaxAmount' => round((float)$order->getTaxRefunded(), 2),
            ];
        }
        $refunded = [];
        $lines = [];
        foreach ($this->lineItemBuilder->buildForOrder($order) as $line) {
            $lines[$line['productSKU']][] = $line;
        }
        foreach ($creditmemo->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if ((float)$item->getQty() <= 0 || !$orderItem || $orderItem->getParentItem()) {
                continue;
            }
            $line = $lines[(string)$orderItem->getSku()][0] ?? [
                'productID' => (string)$orderItem->getProductId(),
                'productSKU' => (string)$orderItem->getSku(),
                'productTitle' => (string)$orderItem->getName(),
            ];
            unset($line['productStrikeThroughPrice']);
            $line['productQuantity'] = (int)$item->getQty();
            $line['productPrice'] = round((float)($item->getPriceInclTax() ?: $item->getPrice()), 2);
            $line['productTotalPrice'] = round((float)$item->getRowTotalInclTax(), 2);
            $refunded[] = $line;
        }

        return [
            'refundedLineItems' => $refunded,
            'totalRefundedAmount' => round((float)$creditmemo->getGrandTotal(), 2),
            'totalRefundedTaxAmount' => round((float)$creditmemo->getTaxAmount(), 2),
        ];
    }

    private function getStatusUrl(Order $order): string
    {
        $store = $this->storeManager->getStore((int)$order->getStoreId());
        $base = $store->getBaseUrl(UrlInterface::URL_TYPE_LINK);
        if ($order->getCustomerId() && !$order->getCustomerIsGuest()) {
            return $base . 'sales/order/view/order_id/' . (int)$order->getEntityId() . '/';
        }

        return $base . 'sales/guest/form/';
    }

    /**
     * @return array<int, array{code: string, amount: float, type: string}>
     */
    private function getDiscounts(Order $order): array
    {
        $amount = round(abs((float)$order->getDiscountAmount()), 2);
        if ($amount <= 0) {
            return [];
        }
        $code = (string)($order->getCouponCode() ?: $order->getDiscountDescription());

        return $code !== '' ? [['code' => $code, 'amount' => $amount, 'type' => 'fixed']] : [];
    }

    private function getPaymentTitle(Order $order): string
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return '';
        }
        $title = (string)$payment->getAdditionalInformation('method_title');
        if ($title === '') {
            try {
                $title = (string)$payment->getMethodInstance()->getTitle();
            } catch (\Exception $e) {
                $title = (string)$payment->getMethod();
            }
        }

        return $title;
    }

    private function address(?OrderAddressInterface $address): ?array
    {
        if (!$address) {
            return null;
        }
        $street = (array)$address->getStreet();

        return [
            'firstName' => (string)$address->getFirstname(),
            'lastName' => (string)$address->getLastname(),
            'company' => (string)$address->getCompany(),
            'address1' => (string)($street[0] ?? ''),
            'address2' => trim(implode(' ', array_slice($street, 1))),
            'city' => (string)$address->getCity(),
            'state' => (string)$address->getRegion(),
            'stateCode' => (string)$address->getRegionCode(),
            'zip' => (string)$address->getPostcode(),
            'country' => (string)$address->getCountryId(),
            'countryCode' => (string)$address->getCountryId(),
            'phone' => (string)$address->getTelephone(),
        ];
    }

    private function isoDate(string $mysqlDate): string
    {
        $time = $mysqlDate !== '' ? strtotime($mysqlDate . ' UTC') : time();

        return gmdate('Y-m-d\TH:i:s\Z', $time ?: time());
    }
}
