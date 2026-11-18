# Changelog

All notable changes of sevenx_paypal. Versions follow doc/upgrading.md: the last position counts on (1.0.9 is followed
by 1.0.10), and a published version is never changed.

## 1.1.0 (2026-11-17)

- Added: Saved payment methods with PayPal's Vault v3 (Vault=enabled): save the buyer's PayPal (or a card where PayPal
  allows it) during a checkout (store_in_vault ON_SUCCESS) or without a purchase (setup token, then payment token);
  only PayPal's token and customer ids and a masked label are stored, never card data.
- Added: Merchant-initiated charges of a saved method (vault_id with stored_credential) for renewals.
- Added: The buyer's page sevenx_paypal/methods (list, remove, save a PayPal account) and the JS SDK's returning-buyer
  flow (data-user-id-token from an id_token).
- Added: The webhooks VAULT.PAYMENT-TOKEN.CREATED and DELETED; --create-webhook adds new events to this site's webhook.
- Added: The tables on all five databases: Oracle and MongoDB through the kernel's schema handlers
  (sql/oracle/schema.sql, sql/mongodb/schema.json, --write-schema-files), the new table sevenx_paypal_vault.
- Fixed: PayPal-Request-Ids stay within 64 characters (PayPal answered 422 to an order that saves a card with a longer one).
- Updated: The documentation (doc/vault.md and the pages it touches) and the tests.

## 1.0.0 (2026-11-17)

- Added: PayPal Checkout on PayPal's current REST APIs: Orders v2 (create, capture, authorize), Payments v2
  (authorizations, captures, refunds) and Webhooks v1 (create, list, signature verification).
- Added: The PayPal JS SDK buttons for any page (PayPal, Pay Later and cards where PayPal offers them), with JSON
  routes that price every payment on the server.
- Added: The shop integration: the buttons next to a card gateway's form on the payment step, and the gateway
  Sevenx_PayPal for shops that offer PayPal alone. A completed capture marks the order paid (status 1000).
- Added: The webhook endpoint sevenx_paypal/webhook: verified deliveries, stored once by event id, handled by the
  built-in handler and by the handlers of other extensions.
- Added: The status page Setup > PayPal and the console bin/php/paypal.php (status, create the webhook, replay an event).
- Added: The console creates the tables (--install-schema) and replays an event (--replay).
- Added: A payment source given by the server (a vaulted account later; the sandbox test card in the tests; never a
  card in live mode), return URLs for approvals outside the buttons' popup, form fields sent with the buttons.
- Added: The documentation suite in doc/ (overview, requirements, install, PayPal account setup, configuration,
  checkout, Katalyst, webhooks, refunds, app API, security, testing, operations, upgrading, FAQ, troubleshooting)
  with screenshots.
- Added: Unit tests without database or network, including a check that every setting is documented and every
  documentation link resolves.
