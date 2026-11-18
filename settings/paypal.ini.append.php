<?php /* #?ini charset="utf-8"?

# sevenx_paypal: PayPal Checkout for Exponential (doc/configuration.md lists every setting with its default).
# Credentials are NEVER written here: the settings below name entries of the secret store (Setup > Secrets, expSecrets);
# put the site's own values in settings/override/paypal.ini.append.php or a siteaccess's paypal.ini.append.php.

[PayPalSettings]
# Who is offered PayPal: disabled (nobody), testers (the user ids of TestUsers[] only) or enabled (every buyer).
# Set it per siteaccess in settings/siteaccess/<siteaccess>/paypal.ini.append.php, or list the siteaccesses below.
Enabled=disabled
TestUsers[]
# The siteaccesses PayPal is offered on (empty: all of them, as far as Enabled allows).
EnabledSiteAccesses[]
# sandbox (api-m.sandbox.paypal.com, sandbox accounts, no real money) or live (api-m.paypal.com).
Mode=sandbox
# The secret store names of the REST app's credentials, per mode. The webhook id is stored there too by the console
# command or the status page when the webhook is created (it is not secret); <Mode>WebhookId= overrides it.
SandboxClientIdSecret=paypal-sandbox-client-id
SandboxSecretSecret=paypal-sandbox-secret
SandboxWebhookIdSecret=paypal-sandbox-webhook-id
SandboxWebhookId=
LiveClientIdSecret=paypal-live-client-id
LiveSecretSecret=paypal-live-secret
LiveWebhookIdSecret=paypal-live-webhook-id
LiveWebhookId=
# Whether a sandbox capture completes an order (a shop order is marked paid). Only for test users of a test shop:
# with it disabled a sandbox payment is verified and logged but nothing is delivered.
AcceptSandboxPayments=disabled
# The currency of payments that do not name their own (a shop order uses its product collection's currency).
Currency=USD
# The name PayPal shows to the buyer on its pages (empty: the name of the PayPal business account).
BrandName=
# The language of PayPal's pages, e.g. en_US (empty: PayPal decides from the buyer's browser).
Locale=
# Funding sources of the JS SDK (doc/checkout.md "Pay Later and cards"): paylater, card, venmo ... PayPal shows only
# what it offers the buyer's country and the account.
EnableFunding[]
EnableFunding[]=paylater
DisableFunding[]
# The PayPal buttons on the shop's payment step (design:sevenx_paypal/shop_buttons.tpl): enabled or disabled.
ShopCheckout=disabled
# Saved payment methods (Vault v3, doc/vault.md): enabled lets buyers save their PayPal at checkout or on
# sevenx_paypal/methods, and lets extensions charge a saved method later (renewals). The PayPal app needs "Save payment
# methods" (Vault) switched on in the Developer Dashboard.
Vault=disabled
# Seconds an API call may take.
ApiTimeout=60
# The log file of payments and webhooks in var/log (ids, amounts and states only).
LogFile=paypal.log

[WebhookSettings]
# The URL PayPal delivers to (empty: https://<SiteURL>/sevenx_paypal/webhook).
Url=
# How a delivery's signature is checked: api (PayPal's verify-webhook-signature), local (PayPal's certificate and
# the CRC32 rule, no API call) or both.
Verification=api
# More handlers of webhook events (classes implementing sevenxPayPalWebhookHandler), after the built-in one.
Handlers[]

*/ ?>
