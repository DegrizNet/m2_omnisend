# Changelog

## 1.0.6 - 2026-10-16

- Settings: the "No Omnisend account yet?" link appears once (next to the API key), not twice.

## 1.0.5 - 2026-10-15

- Release notes for the module page (release-notes.json).

## 1.0.4 (2026-10-15)

- Settings: "Create a free Omnisend account" link next to the API key (Omnisend partner link, marked as such), and
  where to find the key in Omnisend. README: the same link with a note on the partner commission.

## 1.0.3 (2026-10-15)

- Products and categories are sent with the right method the first time (new table `magelan_omnisend_catalog`):
  POST for new ones, PUT (products) or PATCH (categories) for known ones. Omnisend counts every 404 and 409 as
  a failed sync, so 1.0.2 ("PUT, then POST on 404") still showed "Products sync issue found" after each new product.
  On upgrade the table is filled from products and categories already sent through the queue.
- DELETE only for products and categories Omnisend has: a variant or hidden product saved again no longer
  costs a DELETE that answers 404.

## 1.0.2 (2026-10-14)

- Products new to Omnisend are created with POST: in API 2026-03-15 PUT only replaces and answers 404.

## 1.0.1 (2026-10-14)

- The web snippet works with only a brand ID (enabled, no API key yet): Omnisend forms and page views can start
  before the API key is there. Events, contacts and catalog still wait for the key.

## 1.0.0 (2026-10-14)

First release.

- Omnisend API 2026-03-15 (default) and v5, outbox table with cron, retries and rate-limit handling.
- Events: added product to cart, started checkout (standard checkout, Swissup Firecheckout, guest e-mail at
  checkout), placed order, paid for order, order fulfilled (tracking links), order canceled, order refunded.
- Signed cart-restore link for abandoned-cart e-mails.
- Contacts: newsletter consent with IP and browser, customer accounts, buyers from orders.
- Catalog: products with variants and categories; full import from the CLI.
- Web snippet after cookie consent (Swissup GDPR or Magento cookie notice), viewed product, buyer
  identification on the success page.
