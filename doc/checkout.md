# Checkout

## The flow

```
browser (PayPal buttons)            your site (JSON routes)                 PayPal
------------------------            -----------------------                 ------
click PayPal / Pay Later / Card --> POST .../create
                                    prices the order on the server  ------> POST /v2/checkout/orders (intent, amount,
                                    writes sevenx_paypal_transaction          custom_id, PayPal-Request-Id)
                                <-- { ok, order_id }                <------ order id
PayPal's popup: the buyer approves
onApprove                       --> POST .../capture { paypalOrderId }
                                    checks the order is this buyer's ------> POST /v2/checkout/orders/{id}/capture
                                    amount check of the capture      <------ capture (COMPLETED, PENDING, ...)
                                    marks the order paid
                                <-- { ok, paid, message, redirect }
redirect to the done page                                            ......> webhook PAYMENT.CAPTURE.COMPLETED (verified,
                                                                              stored once, applied idempotently)
```

**Server-side amounts only.** The create route computes the amount from the order (the shop: the order's
`total_inc_vat`, coupons, shipping and tax included, in the product collection's currency). Nothing the browser sends
counts as an amount. The capture is checked against the row written at create: status `COMPLETED`, `custom_id`,
currency and amount must match, or the order is not paid (`MISMATCH`).

## The shop

### Next to a card gateway (recommended)

The card gateway's payment template includes `design:sevenx_paypal/shop_buttons.tpl` (see [install.md](install.md)
step 7). The buttons call:

- `POST sevenx_paypal/shop/create` `{orderId}`: a PayPal order for the shop order of this checkout (the session's
  `MyTemporaryOrderID`, else the posted id, always the current user's); `custom_id` `shop-order:<id>`.
- `POST sevenx_paypal/shop/capture` `{orderId, paypalOrderId}`: captures, checks, marks the order paid (status 1000).

The page then reloads `shop/checkout`; the payment workflow sees a paid order (the card gateway accepts status 1000,
as after its own payment), the kernel activates the order, sends its mails and runs every post-checkout integration.

### PayPal as the only gateway

Select **PayPal** (`Sevenx_PayPal`) in the payment workflow event (Setup > Workflows, the event "Payment gateway").
Its payment step is `design:workflow/eventtype/result/sevenx_paypal_form.tpl` with the same buttons and a Cancel
button. The kernel's own choice between several gateways adds a step and keeps the choice on the event, not per
buyer; prefer the buttons next to the card form when you offer both.

## Pay Later and cards

The JS SDK is loaded with `enable-funding=paylater` by default (`EnableFunding[]`). PayPal decides per buyer what to
show: Pay Later where the buyer's country and amount qualify, the **Debit or Credit Card** button (cards typed into
PayPal's own fields) where PayPal offers guest checkout. `DisableFunding[]=card` hides the card button,
`DisableFunding[]=paylater` Pay Later.

## Failure, cancel, pending

| What happens | What the buyer sees | The order |
| --- | --- | --- |
| The buyer closes PayPal's popup | "The PayPal payment was cancelled. Nothing was charged." | unpaid; the buyer can pay by card or try again |
| PayPal declines the funding source (`INSTRUMENT_DECLINED`) | the buttons restart so the buyer can choose another one | unpaid |
| The capture fails otherwise | "The payment did not go through. Nothing was charged ..." | unpaid |
| The total changed after the PayPal order was made | "Your order total changed. Nothing was charged; please pay again." | unpaid |
| PayPal answers `PENDING` (e.g. an eCheck) | "PayPal is still processing your payment ..." | unpaid until `PAYMENT.CAPTURE.COMPLETED` arrives; the webhook marks it paid |
| The capture does not match the order | a message to contact the shop | unpaid; the row is `MISMATCH` |
| Sandbox capture with `AcceptSandboxPayments=disabled` | "A sandbox payment was verified ..." | unpaid |

## Order states (sevenx_paypal_transaction.status)

`CREATED` (made, not approved) -> `APPROVED` (webhook) -> `COMPLETED` (captured and matching) ->
`PARTIALLY_REFUNDED` / `REFUNDED`. A hold: `CREATED` -> `AUTHORIZED` -> `COMPLETED` (captured) or `VOIDED`. Failures:
`PENDING`, `FAILED`, `MISMATCH`. A paid or refunded row is never set back by a late webhook delivery.

## Buttons for any page

```
{include uri='design:sevenx_paypal/buttons.tpl' id='my-paypal' context='mine'
         create='mymodule/paypal/create'|ezurl( 'no' ) capture='mymodule/paypal/capture'|ezurl( 'no' ) done='mymodule/thanks'|ezurl( 'no' )
         body=$paypal_body intent='capture' label='Or pay with PayPal'}
```

- `create` answers `{ok, order_id}` (or `{ok: false, message}`); `capture` answers `{ok, paid, message, redirect}`
  (`restart: true` restarts the buttons). Both get the JSON `body` (a string made in PHP; template strings cannot hold
  braces) plus `paypalOrderId` on capture. `form` and `fields` (`"FieldName:key,..."`) add the current values of a
  form's fields. A signed-in session sends the form token in `X-CSRF-Token`.
- `intent='authorize'` loads the SDK for AUTHORIZE orders.
- Your routes use `sevenxPayPalPayments::instance()`: `begin()` (with `context`, `reference`, `custom_id`,
  `amount_minor`, `currency`, `intent`, `description`, `user_id`, `meta`), `capture()`, `authorize()`,
  `captureAuthorization()`, `void()`, `refund()`.
- `fetch( 'sevenx_paypal', 'checkout', hash( ... ) )` tells a template whether the user gets PayPal at all.

## A payment source from the server

`begin( array( ..., 'payment_source' => array( ... ) ) )` creates the order with a payment source and no approval
step: for a vaulted PayPal account (Vault v3), or PayPal's sandbox test card in the end-to-end tests. A `card` source
is refused in live mode: card numbers never pass through this server.
