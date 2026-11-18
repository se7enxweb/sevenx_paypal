# Troubleshooting

PayPal answers errors with a `name`, a `message`, `details[].issue` and a `debug_id`. sevenx_paypal shows the issue
(or the name) and keeps the debug id in `var/log/paypal.log`; give PayPal support the debug id.

## Credentials and connection

| Message | Meaning | Fix |
| --- | --- | --- |
| `invalid_client` / "Client Authentication failed" (401 on `/v1/oauth2/token`) | wrong client id or secret, or a sandbox pair used against live (or the reverse) | store the right pair under the mode's names; check `Mode` |
| "PayPal is not set up: no sandbox client id in the secret store" | the secret store entry is missing or named differently | Setup > Secrets, or `SandboxClientIdSecret` |
| "the live mode is refused while tests run (SEVENX_PAYPAL_TESTS)" | a process with `SEVENX_PAYPAL_TESTS=1` tried live | unset it outside the tests |
| "PayPal could not be reached: ..." | no outgoing https to api-m.paypal.com | firewall, DNS, proxy |
| `NOT_AUTHORIZED` / `PERMISSION_DENIED` | the app lacks a feature (e.g. Accept payments) | enable it in the app's settings |

## Orders and captures

| Issue | Meaning | What happens here |
| --- | --- | --- |
| `ORDER_NOT_APPROVED` | capture before the buyer approved (or after a cancel) | not paid; the buyer can approve again |
| `ORDER_ALREADY_CAPTURED` | a second capture | sevenx_paypal reads the existing capture instead |
| `ORDER_ALREADY_AUTHORIZED` | a second authorize | the existing authorization is read |
| `INSTRUMENT_DECLINED` | the funding source was declined | the buttons restart so the buyer picks another one |
| `PAYER_ACTION_REQUIRED` | the buyer must do something at PayPal (3-D Secure, a new source) | the buttons restart |
| `UNPROCESSABLE_ENTITY` + `DUPLICATE_INVOICE_ID` | the same `invoice_id` was used before | sevenx_paypal does not send invoice ids for orders by default; check custom code |
| `CURRENCY_NOT_SUPPORTED` | the account cannot take that currency | `Currency`, or the shop's currency |
| `AMOUNT_MISMATCH` | item totals do not add up (custom code sending items) | send only the total |
| `RESOURCE_NOT_FOUND` | an id of the other mode, or a mistyped id | check `Mode` |
| `AUTHORIZATION_EXPIRED` | the hold is older than 29 days | create a new order |
| `AUTHORIZATION_VOIDED` | the hold was released | nothing to capture |
| `MAX_CAPTURE_COUNT_EXCEEDED` | a hold was captured already | read the capture |
| `CAPTURE_FULLY_REFUNDED` / `REFUND_AMOUNT_EXCEEDED` | nothing (more) to refund | the row already shows it |
| `REFUND_TIME_LIMIT_EXCEEDED` | older than 180 days | refund outside PayPal |

States on the status page:

- `MISMATCH` (`amount_mismatch`, `currency_mismatch`, `order_mismatch`): the capture does not pay what the order says;
  the order stays unpaid. Compare the order and the capture at PayPal; refund the capture if it was wrong.
- `PENDING`: PayPal holds the money (eCheck, review); the webhook completes it.
- `FAILED` (`denied`, `declined`): nothing was taken.

## Webhooks

| Symptom | Fix |
| --- | --- |
| 401 `signature` | the webhook id is not this URL's (status page: "the stored id differs"), or the body was changed on the way (a proxy re-encoding JSON); with `Verification=local` the certificate host must be PayPal's |
| 503 `not configured` | no credentials or no webhook id in this mode |
| 500 and "not applied" | a handler failed: `paypal.log` names it; PayPal delivers again, or `--replay=WH-...` |
| Events of payments that are not this site's ("unknown") | the app is shared; harmless |
| Simulated events fail | PayPal does not sign them for your webhook; test with a real sandbox payment |

## Buttons

| Symptom | Fix |
| --- | --- |
| No buttons at all | see [operations.md](operations.md) "Common errors"; the page's Content-Security-Policy must allow PayPal's hosts |
| "The server did not answer" | a firewall or browser check answered the JSON route with a page: allow the route ([install.md](install.md)) |
| The popup closes and nothing happens | a popup blocker; the buttons open PayPal from the click itself |
| Pay Later is missing | PayPal shows it only where the buyer and amount qualify |
