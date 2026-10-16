<?php
/**
 * End-to-end test of Magelan_Omnisend on a real Magento install, against the fake API in mock.php.
 *
 * Usage (on the server):
 *   php omnisend_e2e.php <magento root> <base url> <basic auth user:pass|-> <customer email> <customer password> <mock dir>
 * The mock must run: (cd <mock dir> && php -S 127.0.0.1:18765 mock.php)
 * The script points Magelan_Omnisend at the mock (default scope) and places, invoices, ships and
 * refunds one test order and cancels a second one. Run it on a development copy only.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

[$root, $base, $auth, $customerEmail, $customerPassword, $mockDir] = array_slice($argv, 1, 6) + array_fill(0, 6, null);
if (!$mockDir) {
    fwrite(STDERR, "usage: php omnisend_e2e.php <root> <base url> <user:pass|-> <customer email> <password> <mock dir>\n");
    exit(2);
}
$base = rtrim($base, '/') . '/';
$auth = $auth === '-' ? null : $auth;
require $root . '/app/bootstrap.php';
$om = Bootstrap::create($root, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('crontab');
$db = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$failures = 0;
$tmp = sys_get_temp_dir() . '/omnisend-e2e-' . getmypid();
@mkdir($tmp);
$host = parse_url($base, PHP_URL_HOST);

function check(bool $ok, string $what, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS ' : '  FAIL ') . $what . ($detail !== '' ? " - $detail" : '') . "\n";
    $failures += $ok ? 0 : 1;
}

function http(string $jar, string $method, string $url, $body = null, array $headers = []): array
{
    global $auth, $base;
    $ch = curl_init(str_starts_with($url, 'http') ? $url : $base . ltrim($url, '/'));
    curl_setopt_array($ch, [
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'omnisend-e2e', CURLOPT_TIMEOUT => 90,
    ]);
    if ($auth) {
        curl_setopt($ch, CURLOPT_USERPWD, $auth);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
    }
    $raw = (string)curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/^location:\s*(\S+)/mi', substr($raw, 0, $headerSize), $m);

    return ['status' => $status, 'location' => $m[1] ?? '', 'body' => substr($raw, $headerSize)];
}

function newJar(string $name, array $cookies = []): string
{
    global $tmp, $host;
    $jar = "$tmp/$name.txt";
    $lines = ['# Netscape HTTP Cookie File'];
    foreach ($cookies + ['form_key' => 'e2eFormKey123456'] as $k => $v) {
        $lines[] = implode("\t", [$host, 'FALSE', '/', 'TRUE', '0', $k, $v]);
    }
    file_put_contents($jar, implode("\n", $lines) . "\n");

    return $jar;
}

function addToCart(string $jar, array $product): array
{
    $body = ['product' => $product['id'], 'qty' => 1, 'form_key' => 'e2eFormKey123456'];
    foreach ($product['super'] ?? [] as $attr => $value) {
        $body["super_attribute[$attr]"] = $value;
    }

    return http($jar, 'POST', 'checkout/cart/add/', $body);
}

function cartCount(string $jar): int
{
    $r = http($jar, 'GET', 'customer/section/load/?sections=cart&force_new_section_timestamp=true', null,
        ['X-Requested-With: XMLHttpRequest']);

    return (int)(json_decode($r['body'], true)['cart']['summary_count'] ?? -1);
}

function rows(int $afterId, ?string $type = null, ?string $action = null): array
{
    global $db;
    $select = $db->select()->from('magelan_omnisend_queue')->where('queue_id > ?', $afterId)->order('queue_id');
    $type && $select->where('entity_type = ?', $type);
    $action && $select->where('action = ?', $action);
    $rows = $db->fetchAll($select);
    foreach ($rows as &$row) {
        $row['payload'] = $row['payload'] ? json_decode($row['payload'], true) : null;
    }

    return $rows;
}

function lastId(): int
{
    global $db;

    return (int)$db->fetchOne('SELECT MAX(queue_id) FROM magelan_omnisend_queue');
}

function quoteLines(int $quoteId): array
{
    global $db;

    return $db->fetchAll($db->select()->from('quote_item')->where('quote_id = ?', $quoteId)->where('parent_item_id IS NULL'));
}

function mockRequests(): array
{
    global $mockDir;
    $file = $mockDir . '/requests.jsonl';

    return is_file($file) ? array_map(fn($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES)) : [];
}

function process(): array
{
    global $om, $db;
    // Due now, including rows delayed on purpose (e-mail settle time, fulfilment delay).
    $db->update('magelan_omnisend_queue', ['next_attempt_at' => null], ['status = ?' => 'pending']);

    return $om->create(\Magelan\Omnisend\Model\Queue\Processor::class)->process(500, 600);
}

// ------------------------------------------------------------------ configuration
echo "Configuration (default scope -> mock)\n";
$writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
$encryptor = $om->get(\Magento\Framework\Encryption\EncryptorInterface::class);
$db->delete('core_config_data', ['path = ?' => 'magelan_omnisend/general/brand_id']);
foreach ([
    'magelan_omnisend/general/enabled' => '1',
    'magelan_omnisend/general/api_key' => $encryptor->encrypt('mock-api-key'),
    'magelan_omnisend/general/api_url' => 'http://127.0.0.1:18765',
    'magelan_omnisend/general/debug' => '1',
] as $path => $value) {
    $writer->save($path, $value);
}
$om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
$om->get(\Magento\Framework\App\Cache\Manager::class)->clean(['config', 'full_page']);
@unlink($mockDir . '/requests.jsonl');
@unlink($mockDir . '/categories.json');
@unlink($mockDir . '/products.json');
// The mock starts empty, so nothing is known to it yet.
$db->delete('magelan_omnisend_catalog');
$config = $om->get(\Magelan\Omnisend\Model\Config::class);
check($config->isActive(1), 'active for store 1');
check($config->getApiKey(1) === 'mock-api-key', 'API key decrypted', $config->getApiKey(1));
check($config->getApiUrl() === 'http://127.0.0.1:18765/api', 'API URL with /api', $config->getApiUrl());

// ------------------------------------------------------------------ products
$stock = $om->get(\Magento\CatalogInventory\Helper\Stock::class);
$simples = $om->create(\Magento\Catalog\Model\ResourceModel\Product\Collection::class)
    ->addAttributeToSelect(['name', 'price'])->addAttributeToFilter('type_id', 'simple')
    ->addAttributeToFilter('status', 1)->addAttributeToFilter('visibility', 4)
    ->addAttributeToFilter('price', ['gt' => 5])->addAttributeToFilter('required_options', 0)->setPageSize(30);
$stock->addInStockFilterToCollection($simples);
$simple = null;
foreach ($simples as $p) {
    $simple = ['id' => (int)$p->getId(), 'sku' => $p->getSku(), 'price' => (float)$p->getPrice()];
    break;
}
$configurables = $om->create(\Magento\Catalog\Model\ResourceModel\Product\Collection::class)
    ->addAttributeToSelect(['name'])->addAttributeToFilter('type_id', 'configurable')
    ->addAttributeToFilter('status', 1)->addAttributeToFilter('visibility', 4)->setPageSize(30);
$stock->addInStockFilterToCollection($configurables);
$configurable = null;
foreach ($configurables as $p) {
    $type = $p->getTypeInstance();
    foreach ($type->getUsedProducts($p) as $child) {
        if (!$child->isSaleable()) {
            continue;
        }
        $super = [];
        foreach ($type->getConfigurableAttributesAsArray($p) as $attr) {
            $super[$attr['attribute_id']] = $child->getData($attr['attribute_code']);
        }
        $configurable = ['id' => (int)$p->getId(), 'child' => (int)$child->getId(), 'sku' => $child->getSku(), 'super' => $super];
        break 2;
    }
}
echo "Products: simple #{$simple['id']}, configurable #" . ($configurable['id'] ?? '-') . "\n";

// ------------------------------------------------------------------ A: logged-in customer
echo "\nA) Logged-in customer: added product to cart, started checkout\n";
$customerId = (int)$db->fetchOne('SELECT entity_id FROM customer_entity WHERE email = ?', [$customerEmail]);
$db->update('quote', ['is_active' => 0], ['customer_id = ?' => $customerId, 'is_active = ?' => 1]);
$jarA = newJar('customer');
http($jarA, 'GET', 'customer/account/login/');
$login = http($jarA, 'POST', 'customer/account/loginPost/', [
    'form_key' => 'e2eFormKey123456', 'login[username]' => $customerEmail, 'login[password]' => $customerPassword,
]);
check($login['status'] === 302 && !str_contains($login['location'], 'login'), 'login', $login['location']);

$start = lastId();
addToCart($jarA, $simple);
$rows = rows($start, 'event', 'added product to cart');
check(count($rows) === 1, 'simple product -> one "added product to cart"', count($rows) . ' rows');
$event = $rows[0]['payload'] ?? [];
$quoteIdA = (int)($event['properties']['cartID'] ?? 0);
$line = quoteLines($quoteIdA)[0] ?? [];
check(($event['contact']['email'] ?? '') === $customerEmail, 'contact = customer e-mail');
check(($event['eventVersion'] ?? null) === '' && ($event['origin'] ?? '') === 'api', 'envelope: origin api, no version');
check(abs(($event['properties']['addedItem']['productPrice'] ?? 0) - (float)($line['price_incl_tax'] ?? -1)) < 0.01,
    'price incl. tax', ($event['properties']['addedItem']['productPrice'] ?? '?') . ' vs ' . ($line['price_incl_tax'] ?? '?'));
check(str_contains($event['properties']['abandonedCheckoutURL'] ?? '', 'omnisend_cart/restore?cart=' . $quoteIdA . '&sig='),
    'abandonedCheckoutURL is a signed restore link');

if ($configurable) {
    addToCart($jarA, $configurable);
    $rows = rows($start, 'event', 'added product to cart');
    check(count($rows) === 1, 'second item refreshes the pending row (one row per cart)', count($rows) . ' rows');
    $props = $rows[0]['payload']['properties'] ?? [];
    check(count($props['lineItems'] ?? []) === 2, 'lineItems = whole cart (2 lines)', (string)count($props['lineItems'] ?? []));
    check(($props['addedItem']['productSKU'] ?? '') === $configurable['sku'], 'addedItem = chosen variant SKU');
    check(($props['addedItem']['productVariantID'] ?? '') === (string)$configurable['child'], 'productVariantID = child');
    check(($props['addedItem']['productID'] ?? '') === (string)$configurable['id'], 'productID = configurable parent');
    $expected = 0.0;
    foreach (quoteLines($quoteIdA) as $l) {
        $expected += (float)$l['row_total_incl_tax'] - (float)$l['discount_amount'];
    }
    check(abs(($props['value'] ?? 0) - $expected) < 0.01, 'value = cart total incl. tax', ($props['value'] ?? '?') . " vs $expected");
}

$r = http($jarA, 'GET', 'checkout');
check($r['status'] === 200, 'checkout page opens', (string)$r['status']);
$rows = rows($start, 'event', 'started checkout');
check(count($rows) === 1, '"started checkout" queued (Firecheckout route)', count($rows) . ' rows');
check(($rows[0]['payload']['contact']['email'] ?? '') === $customerEmail, 'started checkout contact');
http($jarA, 'GET', 'checkout');
check(count(rows($start, 'event', 'started checkout')) === 1, 'reload does not queue it twice');
$customerRestoreUrl = $rows[0]['payload']['properties']['abandonedCheckoutURL'] ?? '';

// ------------------------------------------------------------------ B: guest
echo "\nB) Guest known to Omnisend (cookie), then e-mail typed at checkout\n";
$jarG = newJar('guest', ['omnisendContactID' => 'e2eContact123']);
$startG = lastId();
addToCart($jarG, $simple);
$rows = rows($startG, 'event', 'added product to cart');
check(count($rows) === 1 && ($rows[0]['payload']['contact']['id'] ?? '') === 'e2eContact123', 'guest add to cart with contact ID');
$quoteIdG = (int)($rows[0]['payload']['properties']['cartID'] ?? 0);
$guestRestoreUrl = $rows[0]['payload']['properties']['abandonedCheckoutURL'] ?? '';

$jarAnon = newJar('anonymous');
$startAnon = lastId();
addToCart($jarAnon, $simple);
check(count(rows($startAnon, 'event')) === 0, 'unknown visitor: nothing queued');

http($jarG, 'GET', 'checkout');
$rows = rows($startG, 'event', 'started checkout');
check(count($rows) === 1, 'guest started checkout queued');
$guestEmail = 'omnisend-e2e-' . time() . '@degriz.net';
$r = http($jarG, 'POST', 'rest/V1/customers/isEmailAvailable', json_encode(['customerEmail' => $guestEmail]),
    ['Content-Type: application/json']);
check($r['status'] === 200, 'isEmailAvailable call', (string)$r['status']);
$row = rows($startG, 'event', 'started checkout')[0] ?? [];
check(($row['payload']['contact']['email'] ?? '') === $guestEmail, 'typed e-mail replaces the contact ID in the pending row');
check(!empty($row['next_attempt_at']), 'held back a moment while the e-mail is being typed', (string)$row['next_attempt_at']);

// ------------------------------------------------------------------ C: restore links
echo "\nC) Cart restore link\n";
$jarN = newJar('other-device');
check(cartCount($jarN) === 0, 'other device starts empty');
$r = http($jarN, 'GET', $guestRestoreUrl . '&utm_source=omnisend');
check($r['status'] === 302 && str_contains($r['location'], '/checkout/'), 'guest link -> checkout', $r['location']);
check(str_contains($r['location'], 'utm_source=omnisend'), 'campaign parameters kept');
check(cartCount($jarN) === 1, 'cart restored on the other device');
$jarT = newJar('tampered');
$r = http($jarT, 'GET', preg_replace('/sig=(\w)/', 'sig=x', $guestRestoreUrl));
check(str_contains($r['location'], 'checkout/cart'), 'tampered signature -> cart page');
check(cartCount($jarT) === 0, 'tampered link restores nothing');
http($jarT, 'GET', str_replace('cart=' . $quoteIdG, 'cart=' . ($quoteIdG - 1), $guestRestoreUrl));
check(cartCount($jarT) === 0, 'other cart ID with this signature restores nothing');
$jarL = newJar('logged-out');
$r = http($jarL, 'GET', $customerRestoreUrl);
check(str_contains($r['location'], 'customer/account/login'), 'customer cart link -> login first', $r['location']);
check(cartCount($jarL) === 0, 'customer cart not exposed without login');
$r = http($jarA, 'GET', $customerRestoreUrl);
check(str_contains($r['location'], '/checkout/'), 'logged-in customer link -> checkout');

// ------------------------------------------------------------------ D: sending
echo "\nD) Cron sends to the mock\n";
$result = process();
check($result['failed'] === 0, 'nothing failed', json_encode($result));
$requests = mockRequests();
$events = array_values(array_filter($requests, fn($r) => $r['path'] === '/api/events'));
check(count($events) >= 3, 'events posted to /api/events', (string)count($events));
check(($events[0]['auth'] ?? '') === 'Omnisend-API-Key mock-api-key', 'Authorization header');
check(($events[0]['version'] ?? '') === '2026-03-15', 'Omnisend-Version header');
$names = array_column(array_column($events, 'body'), 'eventName');
check(in_array('added product to cart', $names, true) && in_array('started checkout', $names, true), 'both cart events sent');
check((bool)array_filter($events, fn($r) => ($r['body']['contact']['email'] ?? '') === $guestEmail), 'guest e-mail sent');
check(!array_filter($events, fn($r) => str_contains(json_encode($r['body']), ':null')), 'no null values sent');

// ------------------------------------------------------------------ E: order life cycle
echo "\nE) Order: placed, paid, fulfilled, refunded; second order canceled\n";
$placeOrder = function (string $email) use ($om, $simple, $db): ?\Magento\Sales\Model\Order {
    $store = $om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore(1);
    $quote = $om->create(\Magento\Quote\Model\Quote::class)->setStore($store)->setCurrency()->setCustomerIsGuest(true)
        ->setCustomerEmail($email)->setCheckoutMethod('guest');
    $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->getById($simple['id'], false, 1, true);
    $quote->addProduct($product, 1);
    $address = ['firstname' => 'Omni', 'lastname' => 'Send', 'street' => 'Testna 1', 'city' => 'Ljubljana',
        'postcode' => '1000', 'country_id' => 'SI', 'telephone' => '041 123 456', 'email' => $email];
    $quote->getBillingAddress()->addData($address);
    $shipping = $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->collectShippingRates();
    $rate = null;
    foreach ($shipping->getAllShippingRates() as $candidate) {
        $rate = $candidate;
        break;
    }
    if (!$rate) {
        echo "  (no shipping rate available)\n";
        return null;
    }
    $shipping->setShippingMethod($rate->getCode());
    $quote->setInventoryProcessed(false)->collectTotals();
    $method = null;
    foreach ($om->get(\Magento\Payment\Model\MethodList::class)->getAvailableMethods($quote) as $candidate) {
        if ($candidate->isOffline()) {
            $method = $candidate->getCode();
            break;
        }
    }
    if (!$method) {
        echo "  (no offline payment method available for this cart)\n";
        return null;
    }
    echo "  info: payment method $method, shipping " . $rate->getCode() . "\n";
    $quote->getPayment()->setQuote($quote)->importData(['method' => $method]);
    $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
    $order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);

    return $order instanceof \Magento\Sales\Model\Order ? $order : null;
};

$startO = lastId();
$orderEmail = 'omnisend-order-' . time() . '@degriz.net';
$order = $placeOrder($orderEmail);
check($order !== null, 'test order placed', $order ? '#' . $order->getIncrementId() : '');
if ($order) {
    $orderId = (int)$order->getEntityId();
    check(count(rows($startO, 'order', 'placed order')) === 1, '"placed order" queued');
    check(count(rows($startO, 'contact', 'order')) === 1, 'buyer queued as contact');

    $invoice = $om->get(\Magento\Sales\Model\Service\InvoiceService::class)->prepareInvoice($order);
    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE)->register();
    $om->create(\Magento\Framework\DB\Transaction::class)->addObject($invoice)->addObject($invoice->getOrder())->save();
    check(count(rows($startO, 'order', 'paid for order')) === 1, '"paid for order" queued on the paid invoice');

    $order = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($orderId);
    $shipment = $om->get(\Magento\Sales\Model\Convert\Order::class)->toShipment($order);
    foreach ($order->getAllItems() as $item) {
        if ($item->getQtyToShip() && !$item->getIsVirtual()) {
            $shipment->addItem($om->get(\Magento\Sales\Model\Convert\Order::class)->itemToShipmentItem($item)->setQty($item->getQtyToShip()));
        }
    }
    $shipment->register();
    $shipment->addTrack($om->create(\Magento\Sales\Model\Order\Shipment\Track::class)
        ->setCarrierCode('custom')->setTitle('GLS Slovenija')->setTrackNumber('E2E123456'));
    $shipment->getOrder()->setIsInProcess(true);
    $om->create(\Magento\Framework\DB\Transaction::class)->addObject($shipment)->addObject($shipment->getOrder())->save();
    $fulfilled = rows($startO, 'order', 'order fulfilled');
    check(count($fulfilled) === 1 && !empty($fulfilled[0]['next_attempt_at']), '"order fulfilled" queued, held back for tracking numbers');

    $order = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($orderId);
    $creditmemo = $om->get(\Magento\Sales\Model\Order\CreditmemoFactory::class)->createByOrder($order);
    $om->get(\Magento\Sales\Api\CreditmemoManagementInterface::class)->refund($creditmemo, true);
    check(count(rows($startO, 'order', 'order refunded')) === 1, '"order refunded" queued');

    $order2 = $placeOrder('omnisend-cancel-' . time() . '@degriz.net');
    if ($order2) {
        // As in the admin: the order is loaded, then canceled.
        $order2 = $om->create(\Magento\Sales\Model\Order::class)->load($order2->getEntityId());
        $order2->cancel()->save();
        check(count(rows($startO, 'order', 'order canceled')) === 1, '"order canceled" queued');
    }

    $before = count(mockRequests());
    $result = process();
    check($result['failed'] === 0, 'order rows sent without failures', json_encode($result));
    $sent = array_slice(mockRequests(), $before);
    $byName = [];
    foreach ($sent as $request) {
        if ($request['path'] === '/api/events' && ($request['body']['properties']['orderID'] ?? '') === (string)$orderId) {
            $byName[$request['body']['eventName']] = $request['body'];
        }
    }
    foreach (['placed order', 'paid for order', 'order fulfilled', 'order refunded'] as $name) {
        check(isset($byName[$name]) && ($byName[$name]['eventVersion'] ?? '') === 'v2', "\"$name\" sent with eventVersion v2");
    }
    $placed = $byName['placed order']['properties'] ?? [];
    check(($placed['orderNumber'] ?? '') === $order->getIncrementId(), 'orderNumber');
    check(($placed['lineItems'][0]['productSKU'] ?? '') === $simple['sku'], 'line SKU');
    check(!isset($placed['lineItems'][0]['productStrikeThroughPrice']), 'no strike-through price in order events');
    check(isset($placed['lineItems'][0]['productTotalPrice']), 'productTotalPrice present');
    check(in_array($byName['paid for order']['properties']['paymentStatus'] ?? '', ['paid', 'refunded', 'partiallyRefunded'], true), 'paid event: payment status', $byName['paid for order']['properties']['paymentStatus'] ?? '?');
    $tracking = $byName['order fulfilled']['properties']['tracking'] ?? [];
    check(($tracking['code'] ?? '') === 'E2E123456', 'tracking code');
    check(str_contains($tracking['courierURL'] ?? '', 'gls-group.com') && str_contains($tracking['courierURL'] ?? '', 'E2E123456'),
        'GLS tracking link from the rules', $tracking['courierURL'] ?? '');
    check(($byName['order refunded']['properties']['totalRefundedAmount'] ?? 0) > 0, 'refunded amount');
    check(count($byName['order refunded']['properties']['refundedLineItems'] ?? []) === 1, 'refunded line');
    $canceled = array_values(array_filter($sent, fn($r) => ($r['body']['eventName'] ?? '') === 'order canceled'))[0]['body'] ?? [];
    check(($canceled['eventVersion'] ?? '') === 'v2' && ($canceled['properties']['orderID'] ?? '') === (string)$order2?->getEntityId(),
        '"order canceled" sent for the second order');
    check(($canceled['properties']['paymentStatus'] ?? '') === 'voided', 'canceled: payment voided');
    $contact = array_values(array_filter($sent, fn($r) => $r['path'] === '/api/contacts'))[0]['body'] ?? [];
    check(($contact['identifiers'][0]['id'] ?? '') === $orderEmail, 'buyer contact posted');
    check(($contact['identifiers'][1]['id'] ?? '') === '+38641123456', 'phone in E.164');
    check(!isset($contact['identifiers'][0]['channels']), 'buyer contact does not touch the e-mail consent');
}

// ------------------------------------------------------------------ F: newsletter
echo "\nF) Newsletter consent\n";
$startN = lastId();
$newsEmail = 'omnisend-news-' . time() . '@degriz.net';
$jarS = newJar('subscriber');
http($jarS, 'GET', '');
$r = http($jarS, 'POST', 'newsletter/subscriber/new/', ['email' => $newsEmail, 'form_key' => 'e2eFormKey123456']);
$subscriberStatus = (int)$db->fetchOne('SELECT subscriber_status FROM newsletter_subscriber WHERE subscriber_email = ?', [$newsEmail]);
$rows = rows($startN, 'contact', 'subscription');
if ($subscriberStatus === 1) {
    check(count($rows) === 1, 'subscribe -> subscription row');
    $identifier = $rows[0]['payload']['identifiers'][0] ?? [];
    check(($identifier['channels']['email']['status'] ?? '') === 'subscribed', 'status subscribed');
    check(!empty($identifier['consent']['ip']) && !empty($identifier['consent']['userAgent']), 'consent with IP and browser');
} else {
    echo "  info: the storefront form did not subscribe (status $subscriberStatus: confirmation, captcha or consent box); using the subscription manager\n";
    $manager = $om->get(\Magento\Newsletter\Model\SubscriptionManagerInterface::class);
    $subscriber = $manager->subscribe($newsEmail, 1);
    $rows = rows($startN, 'contact', 'subscription');
    if ((int)$subscriber->getSubscriberStatus() === 1) {
        check(count($rows) === 1 && ($rows[0]['payload']['identifiers'][0]['channels']['email']['status'] ?? '') === 'subscribed',
            'subscribe -> subscribed row');
        check(($rows[0]['payload']['identifiers'][0]['consent']['source'] ?? '') === 'magento-admin', 'consent source outside the storefront');
    } else {
        check(count($rows) === 0, 'needs confirmation: nothing sent before it is confirmed');
        $subscriber->confirm($subscriber->getSubscriberConfirmCode());
        check(count(rows($startN, 'contact', 'subscription')) === 1, 'confirmed -> subscribed row');
    }
}
$startU = lastId();
$code = (string)$db->fetchOne('SELECT subscriber_confirm_code FROM newsletter_subscriber WHERE subscriber_email = ?', [$newsEmail]);
$om->get(\Magento\Newsletter\Model\SubscriptionManagerInterface::class)->unsubscribe($newsEmail, 1, $code);
// The subscribe row is still unsent, so it is refreshed with the latest state instead of a second row.
$rows = array_values(array_filter(rows($startN, 'contact', 'subscription'), fn($r) => $r['object_id'] === $newsEmail));
check(count($rows) === 1 && ($rows[0]['payload']['identifiers'][0]['channels']['email']['status'] ?? '') === 'unsubscribed',
    'unsubscribe before sending -> the one row now says unsubscribed');
check(!isset($rows[0]['payload']['identifiers'][0]['consent']), 'no consent record on unsubscribe');

// ------------------------------------------------------------------ G: catalog
echo "\nG) Catalog\n";
$catalogStores = $config->getCatalogStoreIds();
check(count($catalogStores) >= 1, 'catalog store(s): ' . implode(',', $catalogStores));
$startC = lastId();
$catalogQueue = $om->get(\Magelan\Omnisend\Model\CatalogQueue::class);
$catalogQueue->products([$simple['id']] + ($configurable ? [1 => $configurable['child']] : []));
$productRows = rows($startC, 'product');
check(count($productRows) >= ($configurable ? 3 : 1), 'product rows (a variant also queues its configurable)', (string)count($productRows));
$categoryId = (int)$db->fetchOne('SELECT category_id FROM catalog_category_product WHERE product_id = ? AND category_id > 2 LIMIT 1', [$simple['id']]);
$catalogQueue->categories([$categoryId]);
$before = count(mockRequests());
$result = process();
check($result['failed'] === 0, 'catalog sent without failures', json_encode($result));
$sent = array_slice(mockRequests(), $before);
$put = array_values(array_filter($sent, fn($r) => str_starts_with($r['path'], '/api/products') && ($r['body']['id'] ?? '') === (string)$simple['id']))[0]['body'] ?? [];
check(($put['id'] ?? '') === (string)$simple['id'] && !empty($put['url']) && !empty($put['currency']), 'product sent (POST when new, PUT after) with id, url, currency');
check(in_array($put['status'] ?? '', ['inStock', 'outOfStock', 'notAvailable'], true), 'product status', $put['status'] ?? '');
check(count($put['variants'] ?? []) >= 1 && isset($put['variants'][0]['price']), 'variants with price');
if ($configurable) {
    $putC = array_values(array_filter($sent, fn($r) => str_starts_with($r['path'], '/api/products') && ($r['body']['id'] ?? '') === (string)$configurable['id']))[0]['body'] ?? [];
    check(count($putC['variants'] ?? []) > 1, 'configurable sent with its variants', (string)count($putC['variants'] ?? []));
}
check((bool)array_filter($sent, fn($r) => $r['method'] === 'POST' && $r['path'] === '/api/product-categories'), 'category POST');
$catalogErrors = fn(array $sent) => array_map(fn($r) => $r['method'] . ' ' . $r['path'] . ' ' . $r['status'],
    array_filter($sent, fn($r) => str_starts_with($r['path'], '/api/product') && $r['status'] >= 300));
check(!$catalogErrors($sent), 'new products and category: POST, no 404 (Omnisend counts it as a failed sync)', implode(', ', $catalogErrors($sent)));
check((bool)$db->fetchOne('SELECT 1 FROM magelan_omnisend_catalog WHERE entity_type = ? AND object_id = ?', ['product', $simple['id']]), 'product registered as known to Omnisend');
$catalogQueue->products([$simple['id']]);
$catalogQueue->categories([$categoryId]);
$before = count(mockRequests());
process();
$sent = array_slice(mockRequests(), $before);
check((bool)array_filter($sent, fn($r) => $r['method'] === 'PUT' && $r['path'] === '/api/products/' . $simple['id']), 'known product replaced with PUT');
check((bool)array_filter($sent, fn($r) => $r['method'] === 'PATCH'), 'known category renamed with PATCH');
check(!array_filter($sent, fn($r) => $r['method'] === 'POST' && str_starts_with($r['path'], '/api/product')), 'no POST for known items');
check(!$catalogErrors($sent), 'second send: no 404 or 409', implode(', ', $catalogErrors($sent)));
$db->delete('magelan_omnisend_catalog', ['entity_type = ?' => 'product', 'object_id = ?' => $simple['id']]);
$catalogQueue->products([$simple['id']]);
$before = count(mockRequests());
$result = process();
$sent = array_slice(mockRequests(), $before);
check($result['failed'] === 0 && (bool)array_filter($sent, fn($r) => $r['method'] === 'PUT' && $r['path'] === '/api/products/' . $simple['id']),
    'registry lost: POST answers 409, product replaced with PUT', json_encode($result));

// ------------------------------------------------------------------ H: snippet
echo "\nH) Brand ID and snippet\n";
$om->create(\Magelan\Omnisend\Cron\ProcessQueue::class)->execute();
$om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
check($om->create(\Magelan\Omnisend\Model\Config::class)->getBrandId(1) === 'mock-brand-123', 'brand ID filled from /brands/current');
$om->get(\Magento\Framework\App\Cache\Manager::class)->clean(['config', 'full_page']);
$jarP = newJar('page');
$page = http($jarP, 'GET', '')['body'];
check(str_contains($page, 'mock-brand-123') && str_contains($page, 'launcher-v2.js'), 'snippet on the home page');
preg_match('/"consent":"(\w+)"/', $page, $m);
echo "  info: consent source on this shop = " . ($m[1] ?? '?') . "\n";
$productUrl = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->getById($simple['id'], false, 1)->getProductUrl();
$productPage = http($jarP, 'GET', $productUrl)['body'];
check(str_contains($productPage, "'viewed product'") && preg_match('/"price":\d+[,}]/', $productPage) === 1,
    '"viewed product" with the price in cents on the product page');

array_map('unlink', glob("$tmp/*"));
@rmdir($tmp);
echo "\n" . ($failures ? "FAILED: $failures check(s)\n" : "ALL CHECKS PASSED\n");
exit($failures ? 1 : 0);
