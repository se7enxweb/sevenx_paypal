# Security

## Credentials

- The client id and secret are in the kernel's secret store (encrypted), never in an INI file, a template, a log or
  the repository. Settings name the entries only.
- The client id is public by design (the browser's SDK needs it); the secret never leaves the server.
- Access tokens are kept in the PHP process's memory only and renewed before they expire; they are never written to
  disk or logged.
- With `SEVENX_PAYPAL_TESTS=1` in the environment the live mode is refused (the unit tests set it).

## Amounts

- Every amount is computed on the server from the order, the invoice or the quote. The browser and the app send what
  to pay, never how much.
- Amounts are integers of the currency's minor unit; PayPal's decimal strings are parsed exactly (more decimals than
  the currency has are refused, never rounded).
- Every capture and authorization is checked against the row written when the order was made: status, `custom_id`,
  currency and amount. An authorization that does not match is voided at once.
- The shop also checks that the order's total did not change between create and capture.

## Who may pay what

- The shop's routes only pay the session's checkout order of the current user.
- A capture is only done for a PayPal order this site made for that reference (and that user).
- A signed-in session's POSTs carry the form token (ezformtoken, `X-CSRF-Token`).

## Webhooks

- Every delivery is verified (PayPal's API or PayPal's certificate rule) before anything is stored; certificates are
  fetched only from PayPal's API hosts over https.
- Events are stored once (unique event id) and applied idempotently; nothing is delivered twice.

## PCI

Card data is typed into PayPal's own fields (PayPal's popup or hosted fields) and never reaches this server. A
server-side `card` payment source is refused in live mode (it exists for PayPal's sandbox test cards only). The site
stays out of PCI DSS scope for card data as far as PayPal's integration terms say; your own SAQ is yours to answer.

## Logging and personal data

- `var/log/paypal.log` and the tables keep ids (PayPal order, capture, refund, authorization, event), amounts,
  currencies, states and references (`shop-order:12`), never a payer's name, e-mail address, phone or address.
- PayPal's error messages are kept with long digit runs masked; PayPal's `debug_id` is kept for support requests.
- The status page shows the same: no payer data.

## Network

Outgoing calls are https only (`CURLOPT_PROTOCOLS`), with certificate and host checks. The webhook URL must be https.
