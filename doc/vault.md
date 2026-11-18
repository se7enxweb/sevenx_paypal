# Saved payment methods (Vault v3)

Since 1.1.0 a buyer can save their PayPal account (and, where PayPal allows it, a card) for later payments, and an
extension can charge a saved method later without the buyer present (merchant-initiated), e.g. a renewal. It uses
PayPal's **Vault v3** (payment method tokens). Switch it on with `[PayPalSettings] Vault=enabled`; the PayPal app needs
**Save payment methods** (Vault) enabled in the Developer Dashboard.

## What is stored

`sevenx_paypal_vault`, one row per saved method: the Exponential user id, the mode, PayPal's **payment token id** and
**customer id**, the method (`paypal`, `card`), a **masked label** for display (`PayPal b***@example.com`,
`Visa 0026`), its state and dates. Never a card number, expiry, name or address: those stay at PayPal.

## Saving

### During a checkout (save during purchase)

The order is created with a payment source that asks PayPal to keep the method when the payment succeeds:

```json
"payment_source": { "paypal": {
    "attributes": { "vault": { "store_in_vault": "ON_SUCCESS", "usage_type": "MERCHANT", "customer_type": "CONSUMER" },
                    "customer": { "id": "<the buyer's PayPal customer id, when there is one>" } },
    "experience_context": { "shipping_preference": "NO_SHIPPING", "user_action": "PAY_NOW", "brand_name": "..." } } }
```

`sevenxPayPalVault::vaultSource()` builds it; after the capture `rememberFromOrder()` keeps the token the order answers
(`payment_source.paypal.attributes.vault.id`, status `VAULTED`).

- The shop: the payment step shows **"Save my PayPal account for later payments"** to signed-in buyers when Vault is
  on; the buttons send it with the create call.
- Katalyst: the billing page's "save for later" choice also saves PayPal when the buyer pays with the PayPal buttons.

### Without a purchase

1. `POST /v3/vault/setup-tokens` (`startSetup()`), PayPal source with `usage_type MERCHANT`,
   `vault_instruction ON_PAYER_APPROVAL`; the buyer approves in the PayPal buttons (`createVaultSetupToken`) or on the
   approval link.
2. `POST /v3/vault/payment-tokens` with the approved setup token (`finishSetup()`): the payment token is kept.

The buyer's page **`sevenx_paypal/methods`** lists the saved methods, removes them and has the buttons to save a PayPal
account (`design:sevenx_paypal/methods.tpl`, `buttons.tpl` with `intent='vault'`).

## Returning buyers

For a signed-in buyer with a PayPal customer id the buttons load the JS SDK with `data-user-id-token`: an `id_token`
from `POST /v1/oauth2/token` with `response_type=id_token&target_customer_id=<customer id>`
(`sevenxPayPalClient::idToken()`), so PayPal shows the saved account at once.

## Charging a saved method later (merchant-initiated)

```php
$vault = sevenxPayPalVault::instance();
$row = $vault->methods( $userId )[0];
$r = $vault->charge( $row, array( 'reference' => 'renewal:123', 'amount_minor' => 1299, 'currency' => 'USD', 'description' => 'Renewal' ) );
// $r: ok, status COMPLETED, capture_id, order_id; ok false with declined true when PayPal declined it
```

The order carries the token and tells PayPal the buyer is not present:

```json
"payment_source": { "paypal": { "vault_id": "<token>", "stored_credential": { "payment_initiator": "MERCHANT", "charge_pattern": "UNSCHEDULED_POSTPAID", "usage": "SUBSEQUENT" } } }
```

(a card: `card.vault_id` with `stored_credential.payment_type UNSCHEDULED|RECURRING`). PayPal completes it at once; the
capture is checked like every payment (status, custom_id, currency, amount). The `PayPal-Request-Id` makes a rerun of
the same charge return PayPal's first answer.

Katalyst charges a saved PayPal account for renewals when the customer has no saved card ([katalyst.md](katalyst.md)).

## Removing

`delete()` calls `DELETE /v3/vault/payment-tokens/{id}` and marks the row deleted (a token PayPal no longer knows is
removed here all the same). A buyer can also remove it in their PayPal account; the webhook follows.

## Webhooks

| Event | What happens |
| --- | --- |
| `VAULT.PAYMENT-TOKEN.CREATED` | a token of a known PayPal customer is kept for that user (when saved elsewhere) |
| `VAULT.PAYMENT-TOKEN.DELETED` | the row is marked deleted |

`paypal.php --create-webhook` adds both events to this site's existing webhook (only its own).

## Errors

| Issue | Meaning |
| --- | --- |
| `PERMISSION_DENIED`, `NOT_ENABLED_FOR_VAULT` / `VAULT_NOT_ENABLED` | Vault is not enabled for the app |
| `INSTRUMENT_DECLINED` on a charge | the saved method was declined; the caller retries later or asks the buyer |
| `RESOURCE_NOT_FOUND` on a charge or delete | the token was removed at PayPal |
| 422 to a long `PayPal-Request-Id` | PayPal refuses request ids over 64 characters on some calls; the client keeps them shorter |
