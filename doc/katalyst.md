# Katalyst

Katalyst (`sevenx_katalyst`, the hosting and billing platform) uses sevenx_paypal as its second payment gateway, next
to Stripe (the default). Its own documentation is `doc/billing.md` "PayPal" in that extension; this page is the
summary from sevenx_paypal's side.

## One gateway layer

`classes/billing/expkatalystpaymentgateway.php` in Katalyst:

- `expKatalystPaymentGateways::choices()` / `payPalOffered()` decide for every flow which gateways a user may use:
  Stripe first; PayPal when sevenx_paypal offers it to the user (`Enabled`, `TestUsers[]`, `EnabledSiteAccesses[]`)
  and `katalyst.ini [PayPalSettings] Flow_<flow>` is not `disabled` (`invoice`, `topup`, `whmcs`, `domain`, `app`,
  `order`).
- `expKatalystPaymentGateways::get( 'stripe'|'paypal' )` gives an object with `begin()`, `complete()` and `refund()`.
  The billing engine starts and confirms any gateway's payment with `startWithGateway()` and `confirmGateway()`.
- A PayPal payment row of `katalyst_payment` has `gateway = paypal`, `external_id` = the PayPal order id and, once
  paid, `reference` = the capture id. The PayPal order's `custom_id` is `katalyst-pay:<row>`.
- The capture is turned into the engine's "intent" shape, so the same amount check, receipts, invoice numbering,
  account credit and WHMCS bridge apply as for a card.

## The flows

| Flow | Page | PayPal |
| --- | --- | --- |
| Engine invoice (one, or "Pay all") | `katalyst/billing` | buttons in the pay card, next to the card form |
| Add funds (prepayment) | `katalyst/billing` | the same, the amount the customer chose (within the prepayment limits) |
| WHMCS invoices and funds (portal) | `katalyst/billing` on the portal | recorded in WHMCS by `billing.payment.add` / `billing.credit.add` under `[WhmcsPaySettings] PayPalGateway` (default `paypal_ppcpv`) with the capture id, behind the same switches as card payments |
| Order checkout, re-order | the shop's payment step | the shop buttons; the order's invoice gets a payment row with gateway `paypal` |
| Domain order | `katalyst/domainorder` | intent AUTHORIZE: held, captured after the registrar confirmed, voided when it failed |
| App | `katalyst/api/v1/checkout` | [app-api.md](app-api.md) |

The buttons post to `katalyst/paypal/create` and `katalyst/paypal/capture` with a `purpose` (`invoices`, `topup`,
`whmcs_invoices`, `whmcs_funds`, `domain`); Katalyst prices everything from the customer's own data.

## Domains: authorize, then capture

The rule "never charge without delivering" holds with PayPal: the buyer approves an AUTHORIZE order of the quoted
total; the order is placed with method `paypal`; Katalyst authorizes it (the amount is held on the buyer's PayPal
account), registers the name, then captures the authorization and writes the invoice. A failed registration voids
the authorization; nothing is taken. PayPal honors an authorization for 3 days and keeps it capturable for 29.

## Refunds and webhooks

- Refunds from the staff invoice and payment pages go through PayPal's Refunds API (full or partial, a credit note);
  "refund credit" refunds PayPal prepayments too.
- Katalyst registers `expKatalystPayPalWebhookHandler` (`[WebhookSettings] Handlers[]`): a capture the buyer's page did
  not finish is applied, a refund made in PayPal's dashboard is recorded once.
