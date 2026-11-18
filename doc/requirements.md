# Requirements

| What | Version |
| --- | --- |
| Exponential | 6.0 or newer (the legacy kernel with the secret store `expSecrets`) |
| PHP | 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 |
| PHP extensions | `curl`, `json`, `openssl` (and what Exponential needs) |
| Database | PostgreSQL, MySQL or MariaDB, SQLite 3 (`sql/<database>/schema.sql`) |
| Extensions | `ezjscore` is not needed; `ezformtoken` is supported (the buttons send the form token) |
| Outgoing HTTPS | to `api-m.paypal.com` and `api-m.sandbox.paypal.com` (port 443) |
| Incoming HTTPS | PayPal's webhook deliveries to `https://<your site>/sevenx_paypal/webhook` |

## PayPal

- A **PayPal Business account** (live). Personal accounts cannot take payments through the REST APIs.
- A **REST app** in the PayPal Developer Dashboard for the sandbox and one for live: its client id and secret
  ([paypal-account-setup.md](paypal-account-setup.md)).
- Pay Later and the card button depend on the account's country and PayPal's eligibility; nothing has to be set up
  for them here.

## Browsers

The buttons need JavaScript. PayPal's SDK supports the current versions of the common browsers. A page whose
Content-Security-Policy restricts scripts or frames must allow `https://www.paypal.com`, `https://*.paypal.com` and
`https://*.paypalobjects.com` (scripts, frames, connections, images).
