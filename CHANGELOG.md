# Changelog

All notable changes of sevenx_paypal. Versions follow doc/upgrading.md: the last position counts on (1.0.9 is followed
by 1.0.10), and a published version is never changed.

## Unreleased (1.0.0)

- Added: PayPal Checkout on PayPal's current REST APIs: Orders v2 (create, capture, authorize), Payments v2
  (authorizations, captures, refunds) and Webhooks v1 (create, list, signature verification).
- Added: The PayPal JS SDK buttons for any page (PayPal, Pay Later and cards where PayPal offers them), with JSON
  routes that price every payment on the server.
- Added: The shop integration: the buttons next to a card gateway's form on the payment step, and the gateway
  Sevenx_PayPal for shops that offer PayPal alone. A completed capture marks the order paid (status 1000).
- Added: The webhook endpoint sevenx_paypal/webhook: verified deliveries, stored once by event id, handled by the
  built-in handler and by the handlers of other extensions.
- Added: The status page Setup > PayPal and the console bin/php/paypal.php (status, create the webhook, replay an event).
- Added: Unit tests without database or network.
