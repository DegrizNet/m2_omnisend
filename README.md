# Omnisend for Magento 2

Free, open-source integration between **Magento 2 / Adobe Commerce** and **[Omnisend](https://www.omnisend.com/)**.
It sends the cart, checkout and order events Omnisend's automations need (abandoned cart, order confirmation,
shipping, cross-sell), keeps contacts and their newsletter consent in sync, imports the product catalog
and loads the Omnisend web snippet, all without slowing down the storefront.

Built and maintained by **[Degriz](https://www.degriz.net)**, a Magento agency from Slovenia.

No Omnisend account yet? **[Create a free Omnisend account](https://your.omnisend.com/aNkJqZ)**. This is a partner link: if you later pay for Omnisend, Degriz gets a commission from Omnisend at no extra cost to you, which helps keep this module free and maintained.

## Why another Omnisend module?

- The official `omnisend/magento2-plugin` was archived in 2023 and speaks the old v3 API.
- Omnisend retires API **v5 on 30 June 2027**. This module uses the dated API **2026-03-15** by default
  (v5 remains selectable).
- Abandoned-cart e-mails need a link that restores the cart on any device. Most integrations send a plain
  `/checkout` link, which only works in the same browser session.

## Features

| Area | What happens |
|---|---|
| `added product to cart` | Whole cart, prices incl. tax, chosen variant, cart value and a signed restore link. |
| `started checkout` | When the checkout opens for a known visitor, or when a guest types the e-mail. Works with the standard checkout, **Swissup Firecheckout** and Hyvä Checkout. |
| `placed order` | Order, addresses, lines incl. tax, discounts, payment and fulfilment status. |
| `paid for order` | When the invoice is paid (for bank transfers: when you book the payment). |
| `order fulfilled` | On shipment, with the tracking number and a tracking link built from your carrier rules. |
| `order canceled`, `order refunded` | On cancel and credit memo, with refunded lines and amounts. |
| Contacts | Newsletter subscribe and unsubscribe with a consent record (time, IP, browser); customer accounts; buyers from orders (name, address, phone in E.164). A buyer is never subscribed without consent. |
| Catalog | Products (with configurable variants, images, prices incl. tax, stock status) and categories on save, delete, mass update, import and stock change; full import from the CLI. |
| Web tracking | Omnisend snippet, page views, `viewed product`; the buyer is identified on the order success page. Loads only after cookie consent (Swissup GDPR or the Magento cookie notice). |
| Cart restore | `/omnisend_cart/restore?cart=…&sig=…`, signed with the store's crypt key (old keys keep working after a key rotation). Guests get the cart back on any device, customers after logging in. |

Everything goes through an outbox table and cron: a slow or unreachable Omnisend never slows down a page,
failed calls are retried with back-off (and `Retry-After` on rate limits), nothing is lost.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.4 or newer, PHP 8.1 to 8.4
- Cron running (`bin/magento cron:run` every minute)
- Luma or Hyvä storefront (templates use no theme-specific JavaScript)

## Installation

```bash
composer require degriznet/m2_omnisend
bin/magento module:enable Magelan_Omnisend
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode
bin/magento cache:flush
```

Or copy the repository into `app/code/Magelan/Omnisend` and run the same commands from `module:enable` on.

## Configuration

**Stores → Configuration → Customers → Omnisend**

1. In Omnisend: *Store settings → API keys → Create API key* (Contacts, Events, Products, Categories).
2. Paste the key, set *Enabled* to Yes and press **Test Connection**. The brand ID for the snippet is filled
   in automatically.
3. First import of the catalog and the existing newsletter list:

   ```bash
   bin/magento magelan:omnisend:sync categories products subscribers
   ```

4. In Omnisend, use `abandonedCheckoutURL` as the button link of the cart-abandonment e-mails
   (the default templates already do).

Several store views can use different keys (different Omnisend brands). Store views that share a key share one
catalog; it is written from the website's default store view.

Parcel tracking links are rules of the form `carrier | https://…{number}`, matched against the carrier code or
title of the shipment. GLS, Pošta Slovenije, DPD, Express One, UPS, DHL, FedEx and USPS are preset.

## Cookie consent and GDPR

- *Load Snippet: After cookie consent* (default) waits for the Swissup GDPR cookie group you name (usually
  `marketing`) or, without Swissup, for the Magento cookie notice. Without any consent mechanism it loads at once.
- The Omnisend cookies are registered in Swissup GDPR's marketing group.
- Server-side cart events are only sent for visitors who are already known (logged in, typed their e-mail at
  checkout, or came from an Omnisend e-mail). Add Omnisend to your privacy policy as a processor.

## Command line

```bash
bin/magento magelan:omnisend:queue               # counts per type and the last errors
bin/magento magelan:omnisend:queue --process     # send due rows now
bin/magento magelan:omnisend:queue --retry-failed
bin/magento magelan:omnisend:sync products       # categories | products | subscribers
```

*Log API Requests* writes every request and answer to `var/log/magelan_omnisend.log`.

## Tests

- Unit tests: `dev/ci` (`composer install`, then `vendor/bin/phpunit -c phpunit.xml.dist`).
- End-to-end: `dev/e2e/omnisend_e2e.php` against a fake Omnisend (`dev/e2e/mock.php`) on a development copy
  of a shop: cart, checkout, restore links, an order through invoice, shipment, refund and cancel,
  newsletter, catalog and the snippet.

## Support and custom work

Issues and pull requests are welcome. For installation, Omnisend set-up, automations or other Magento work,
contact **[Degriz](https://www.degriz.net)**.

## License

MIT, see [LICENSE](LICENSE). Not affiliated with or endorsed by Omnisend.
