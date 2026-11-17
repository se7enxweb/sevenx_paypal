# sevenx_paypal

PayPal Checkout for [Exponential](https://github.com/se7enxweb/exponential), on PayPal's current REST APIs:

- **Orders v2**: the buyer approves with the PayPal JS SDK buttons (PayPal, Pay Later and debit or credit cards
  where PayPal offers them), the server captures, or authorizes and captures later;
- **Payments v2**: captures, authorizations (capture, void) and refunds, full or partial;
- **Webhooks v1**: this site's webhook is created through the API; every delivery is verified
  (`verify-webhook-signature`, or PayPal's certificate rule), stored once by event id and handled idempotently.

It replaces nothing it does not need to: a shop keeps its card gateway (Stripe, for example) as the default and gets
the PayPal buttons next to it. It does **not** use the classic ezpaypal redirect, IPN or the NVP/SOAP APIs.

Amounts are always computed on the server; nothing the browser sends is trusted. Credentials live in the kernel's
secret store (Setup > Secrets), never in INI files.

## Quick start

```bash
composer require se7enxweb/sevenx_paypal
```

1. Activate the extension (`ActiveExtensions[]=sevenx_paypal`), create its tables from `sql/<database>/schema.sql`,
   regenerate the autoloads and clear the caches ([doc/install.md](doc/install.md)).
2. Store the REST app's client id and secret in the secret store as `paypal-sandbox-client-id` and
   `paypal-sandbox-secret` ([doc/paypal-account-setup.md](doc/paypal-account-setup.md)).
3. Create the webhook: `php extension/sevenx_paypal/bin/php/paypal.php --create-webhook`.
4. Offer PayPal to test users first: `Enabled=testers`, `TestUsers[]=<user id>`, `ShopCheckout=enabled`
   ([doc/configuration.md](doc/configuration.md)).
5. Check Setup > PayPal in the administration.

## Documentation

The full documentation is in [doc/](doc/README.md): overview, requirements, installation, the PayPal account setup,
configuration, checkout, webhooks, refunds, the app API, Katalyst, security, testing, operations, upgrading,
troubleshooting and the FAQ.

## License

GNU General Public License v2.0 (or any later version). Copyright (C) 1998 - 2026 Exponential Foundation and 7x.
