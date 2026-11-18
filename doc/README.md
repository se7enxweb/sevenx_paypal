# sevenx_paypal documentation

PayPal Checkout for Exponential on PayPal's current REST APIs. Start with the [overview](overview.md).

## Reading order

**Site owners** (deciding, setting up, going live)

1. [Overview](overview.md): what it does and which PayPal APIs it uses
2. [Requirements](requirements.md)
3. [PayPal account setup](paypal-account-setup.md): the REST app, credentials, sandbox accounts, the webhook
4. [Configuration](configuration.md): every setting, sandbox and live, who sees PayPal
5. [Operations](operations.md): the going-live checklist and the status page
6. [FAQ](faq.md)

**Developers** (installing, integrating, extending)

1. [Install](install.md)
2. [Checkout](checkout.md): the shop gateway, the buttons for any page, the order states
3. [Webhooks](webhooks.md): verification, idempotency, handlers of other extensions, replay
4. [Refunds](refunds.md)
5. [Katalyst](katalyst.md): invoices, add funds, WHMCS bridge, domain holds, the gateway layer
6. [App API](app-api.md): mobile apps with PayPal's SDKs
7. [Security](security.md)
8. [Testing](testing.md): unit tests and the sandbox end-to-end checks
9. [Upgrading and versions](upgrading.md)

**Support staff** (helping buyers and fixing problems)

1. [Operations](operations.md): the status page, logs, the console
2. [Troubleshooting](troubleshooting.md): PayPal's error messages and what they mean
3. [Refunds](refunds.md)
4. [FAQ](faq.md)

## Screenshots

From a sandbox installation (the site's own names, URL and ids replaced by neutral examples); no payer data.

- [The shop's payment step with the PayPal buttons next to the card form](images/shop-payment-step.png) (sandbox)
- [Setup > PayPal, light](images/admin-status-light.png) and [dark](images/admin-status-dark.png)
- [PayPal's sandbox approval page](images/sandbox-approval-page.png) and its
  [guest card form](images/sandbox-guest-card-form.png) (PayPal's test card and a made-up test identity)
