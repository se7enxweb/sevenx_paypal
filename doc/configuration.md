# Configuration

All settings are in `paypal.ini`, block `[PayPalSettings]` and `[WebhookSettings]`. The extension's
`settings/paypal.ini.append.php` holds the defaults; put your values into `settings/override/paypal.ini.append.php`,
or into `settings/siteaccess/<siteaccess>/paypal.ini.append.php` for one siteaccess. Clear the INI cache after a
change (`php bin/php/ezcache.php --clear-tag=ini`).

## [PayPalSettings]

| Setting | Default | Meaning |
| --- | --- | --- |
| `Enabled` | `disabled` | Who is offered PayPal: `disabled` (nobody), `testers` (only the users of `TestUsers[]`), `enabled` (every buyer). |
| `TestUsers[]` | (empty) | User (content object) ids that see PayPal while `Enabled=testers`. Test accounts only. |
| `EnabledSiteAccesses[]` | (empty) | The siteaccesses PayPal is offered on; empty: all of them, as far as `Enabled` allows. |
| `Mode` | `sandbox` | `sandbox` (api-m.sandbox.paypal.com, test money) or `live` (api-m.paypal.com). |
| `SandboxClientIdSecret` | `paypal-sandbox-client-id` | Secret store name of the sandbox client id. |
| `SandboxSecretSecret` | `paypal-sandbox-secret` | Secret store name of the sandbox secret. |
| `SandboxWebhookIdSecret` | `paypal-sandbox-webhook-id` | Secret store name of the sandbox webhook id (written by `--create-webhook`). |
| `SandboxWebhookId` | (empty) | The sandbox webhook id directly; wins over the secret store entry. |
| `LiveClientIdSecret` | `paypal-live-client-id` | Secret store name of the live client id. |
| `LiveSecretSecret` | `paypal-live-secret` | Secret store name of the live secret. |
| `LiveWebhookIdSecret` | `paypal-live-webhook-id` | Secret store name of the live webhook id. |
| `LiveWebhookId` | (empty) | The live webhook id directly. |
| `AcceptSandboxPayments` | `disabled` | Whether a sandbox capture completes an order (marks a shop order paid). `enabled` only on a site where only test users see PayPal; in live mode it does not matter. |
| `Currency` | `USD` | Currency of payments that do not bring their own (a shop order uses its product collection's currency). |
| `BrandName` | (empty) | The name PayPal shows to the buyer; empty: the PayPal account's name. |
| `Locale` | (empty) | PayPal's language, e.g. `en_US`; empty: PayPal decides. |
| `EnableFunding[]` | `paylater` | Funding sources the buttons ask for (`paylater`, `card`, `venmo` ...); PayPal shows only what it offers. |
| `DisableFunding[]` | (empty) | Funding sources to hide (e.g. `card` to show PayPal only). |
| `ShopCheckout` | `disabled` | The PayPal buttons on the shop's payment step (`design:sevenx_paypal/shop_buttons.tpl`). |
| `ApiTimeout` | `60` | Seconds an API call may take. |
| `LogFile` | `paypal.log` | The log in `var/log` (ids, amounts and states only). |

Optional, not in the default file: `SandboxApiBase` / `LiveApiBase` (another `https://*.paypal.com` API host).

## [WebhookSettings]

| Setting | Default | Meaning |
| --- | --- | --- |
| `Url` | (empty) | The URL PayPal delivers to; empty: `https://<SiteURL>/sevenx_paypal/webhook`. |
| `Verification` | `api` | `api` (PayPal's verify-webhook-signature), `local` (PayPal's certificate and the CRC32 rule, no API call) or `both`. |
| `Handlers[]` | (empty) | More handlers (classes implementing `sevenxPayPalWebhookHandler`) after the built-in one. |

## Secret store names

Only names are configured; the values are in the kernel's secret store (Setup > Secrets, `expSecrets`). A site can
give them any names, e.g. to keep them in one scope with other payment credentials:

```ini
[PayPalSettings]
SandboxClientIdSecret=shop-paypal-sandbox-client-id
SandboxSecretSecret=shop-paypal-sandbox-secret
```

## Per siteaccess

`settings/siteaccess/store/paypal.ini.append.php`:

```ini
[PayPalSettings]
Enabled=enabled
ShopCheckout=enabled
```

or one list in the override: `EnabledSiteAccesses[]=store`.

## Sandbox for test users, then live

A typical rollout:

```ini
# 1. sandbox, test users only, their sandbox orders complete
Mode=sandbox
Enabled=testers
TestUsers[]=123
AcceptSandboxPayments=enabled
ShopCheckout=enabled

# 2. live (the switch): every buyer, real money
Mode=live
Enabled=enabled
AcceptSandboxPayments=disabled
```

## Template fetch

`fetch( 'sevenx_paypal', 'checkout', hash( 'intent', 'capture', 'currency', 'USD', 'context', 'shop' ) )` answers
`enabled`, `sdk_url`, `mode`, `sandbox` and `currency` for the current user ([checkout.md](checkout.md)).
