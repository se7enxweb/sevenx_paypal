# Refunds

## From your code or the staff pages

```php
$r = sevenxPayPalPayments::instance()->refund( $captureId );                 // all that is left
$r = sevenxPayPalPayments::instance()->refund( $captureId, 1000, 'USD', 'Partial refund' ); // $10.00
// $r: ok, refund_id, status (COMPLETED or PENDING), amount_minor; or ok false with PayPal's message and issue
```

- `POST /v2/payments/captures/{capture}/refund` with a `PayPal-Request-Id` made of the capture, what was refunded
  before and the amount: pressing the button twice refunds once.
- Never more than what is left of the capture (the row keeps `refunded_minor`); the row becomes
  `PARTIALLY_REFUNDED` or `REFUNDED`.
- An optional note goes to the buyer (`note_to_payer`).
- Katalyst's staff pages (invoice > Refund a payment, customer > refund credit) call this for PayPal payments and
  write a credit note ([katalyst.md](katalyst.md)).

A shop order paid with PayPal has its capture id on the status page and in `sevenx_paypal_transaction`
(`sevenxPayPalShop::paymentOf( $orderId )`).

## Refunds made in PayPal's dashboard

A refund made at paypal.com arrives as `PAYMENT.CAPTURE.REFUNDED`. The built-in handler records the new total; a
handler of another extension (Katalyst's) records its own side (a credit note) once. A refund made here also arrives
by webhook afterwards and changes nothing more.

## Authorizations

A hold that is not captured is released with `void( $authorizationId )` (nothing was taken, so nothing is refunded).
A captured hold is refunded like any capture.

## Limits

- PayPal allows refunds for 180 days after the capture.
- PayPal keeps its fee on refunds as its current terms say.
- A `PENDING` refund completes later (`PAYMENT.CAPTURE.REFUNDED` then arrives).
