# Webhooks

## The endpoint

`POST https://<your site>/sevenx_paypal/webhook` (module view `sevenx_paypal/webhook`). No sign-in: PayPal's signature
is the credential (`PolicyOmitList[]=sevenx_paypal/webhook`). It answers JSON:

| Status | When | PayPal |
| --- | --- | --- |
| 200 | the event was stored and handled, or it is a replay (`duplicate`) | done |
| 400 | not an event (no JSON, no id or type, over 1 MB) | retries (and gives up) |
| 401 | the signature did not verify | retries |
| 405 | not a POST | - |
| 500 | a handler failed (the event stays "not applied") | delivers again later |
| 503 | PayPal is not set up here (no credentials or no webhook id) | retries |

PayPal retries a delivery that is not answered 2xx for up to three days.

## Signature verification

Every delivery carries the headers `PAYPAL-TRANSMISSION-ID`, `PAYPAL-TRANSMISSION-TIME`, `PAYPAL-TRANSMISSION-SIG`,
`PAYPAL-CERT-URL` and `PAYPAL-AUTH-ALGO`.

- `Verification=api` (default): `POST /v1/notifications/verify-webhook-signature` with the headers, this site's webhook
  id and the event **byte for byte as PayPal sent it** (never decoded and encoded again). Only
  `verification_status: SUCCESS` counts.
- `Verification=local`: PayPal's own rule without an API call: the string
  `<transmission id>|<transmission time>|<webhook id>|<CRC32 of the raw body, unsigned decimal>` must be signed
  (SHA256withRSA) by the certificate at `PAYPAL-CERT-URL`, which must be https on `api.paypal.com`,
  `api-m.paypal.com`, `api.sandbox.paypal.com` or `api-m.sandbox.paypal.com` and valid now.
- `Verification=both`: local first, then the API.

The endpoint tries the configured mode's webhook first and then the other mode's, so the sandbox app's late
deliveries are still verified after a switch to live (each with its own webhook id).

Events PayPal "simulates" in the Developer Dashboard (Webhooks simulator) are not signed for your webhook and are
refused with 401; use a real sandbox payment instead ([testing.md](testing.md)).

## Events handled

See [paypal-account-setup.md](paypal-account-setup.md#4-the-webhook) for the list. The built-in handler
(`sevenxPayPalCoreWebhookHandler`) keeps saved methods right (`VAULT.PAYMENT-TOKEN.CREATED` and `DELETED`,
[vault.md](vault.md)) and `sevenx_paypal_transaction`: a completed capture is checked and applied
(and a shop order marked paid if its page did not do it), pending and denied captures, refunds (the total refunded,
`seller_payable_breakdown.total_refunded_amount`), voided authorizations, approved orders. A delivery that arrives
late (the capture refunded since) never sets a paid or refunded row back.

## Idempotency

- `sevenx_paypal_event` has a unique index on PayPal's event id: a replayed delivery answers `duplicate` and changes
  nothing; two deliveries at the same time cannot both be stored.
- An event whose handlers failed stays "not applied" (`processed = 0`) and is run again on PayPal's next delivery.
- Every handler is idempotent itself: the payment flows check the row's state before they write.

Only the event id, type, resource type and id, the PayPal order id, the status, amount and custom id are kept, never
the payer's name, e-mail or address.

## Handlers of other extensions

```php
class myPayPalHandler implements sevenxPayPalWebhookHandler
{
    public function handle( array $event, ?array $transaction, sevenxPayPalPayments $payments )
    {
        if ( !$transaction || $transaction['context'] !== 'mine' )
            return 'ignored';
        // ... idempotent work ...
        return 'done';
    }
}
```

```ini
[WebhookSettings]
Handlers[]=myPayPalHandler
```

A handler that throws makes the endpoint answer 500 (PayPal delivers again).

## Replay

```bash
php extension/sevenx_paypal/bin/php/paypal.php --replay=WH-XXXXXXXXXXXXXXXXX-XXXXXXXXXXXXXXXXX --mode=sandbox
```

reads the event from PayPal's API (`GET /v1/notifications/webhooks-events/{id}`; no signature needed, it comes from
PayPal over the API) and runs the handlers again. The event ids are on the status page. PayPal's dashboard can also
resend an event (Webhooks > Event).

## Troubleshooting

- Status page: the last events with their result; "not applied" means a handler failed (see `var/log/paypal.log`).
- 401 in the web server log for `/sevenx_paypal/webhook`: the webhook id is missing or of another app/URL
  (`--status` shows the app's webhooks and whether the stored id matches), or a firewall altered the body.
- Nothing arrives: the URL must be reachable from the internet over https without a browser check or basic auth
  ([install.md](install.md) step 6).
