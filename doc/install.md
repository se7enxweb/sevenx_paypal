# Install

## 1. Get the extension

With Composer, from the installation's root:

```bash
composer require se7enxweb/sevenx_paypal
```

The package is of type `ezpublish-legacy-extension`; the installer puts it into `extension/sevenx_paypal`. Without
Composer, clone `https://github.com/se7enxweb/sevenx_paypal` into `extension/sevenx_paypal`.

## 2. Activate it

In `settings/override/site.ini.append.php`:

```ini
[ExtensionSettings]
ActiveExtensions[]=sevenx_paypal
```

If your override resets `[RoleSettings] PolicyOmitList[]` (a line `PolicyOmitList[]` with no value), add the two
entries of the extension's own `settings/site.ini.append.php` there too:

```ini
PolicyOmitList[]=sevenx_paypal/webhook
PolicyOmitList[]=sevenx_paypal/shop
PolicyOmitList[]=sevenx_paypal/methods
```

## 3. Create the tables

```bash
php extension/sevenx_paypal/bin/php/paypal.php --install-schema
```

### The tables

It creates `sevenx_paypal_transaction`, `sevenx_paypal_event` and (1.1) `sevenx_paypal_vault` where they are missing and
leaves existing tables alone, on all five databases of Exponential:

| Database | How |
| --- | --- |
| PostgreSQL, MySQL/MariaDB, SQLite | `sql/<database>/schema.sql` |
| Oracle (the ezoracle extension) | the kernel's schema handler from `sevenxPayPalSchema::definition()` (= `sql/oracle/schema.sql`); `settings/ezoracle.ini.append.php` quotes the reserved column name `mode` |
| MongoDB | the kernel's MongoDB schema handler (= `sql/mongodb/schema.json`: collections and indexes) |

The files can also be run by hand. `paypal.php --write-schema-files` writes the Oracle and MongoDB files again.

## 4. Autoloads, caches, PHP

```bash
php bin/php/ezpgenerateautoloads.php -e
php bin/php/ezcache.php --clear-tag=ini
php bin/php/ezcache.php --clear-id=template,template-override,template-block,design_base,content
```

Reload PHP-FPM (or your PHP server) so it sees the new classes. A site with a Redis/Valkey cache clears that too.

## 5. Credentials and the webhook

Store the REST app's credentials in the secret store and create the webhook:
[paypal-account-setup.md](paypal-account-setup.md). Then check Setup > PayPal in the administration.

## 6. The request shield

A site behind `exp_request_shield` must let PayPal's servers post the webhook (no `Origin`, no browser) and let the
buttons post their JSON routes from the site's own pages. Rules for `settings/request-shield/*.rules`:

```
[SITE-PAYPAL-POST]   allow POST **/sevenx_paypal/webhook **/sevenx_paypal/shop/**
[SITE-PAYPAL-HOOK-X] post-origin except **/sevenx_paypal/webhook
[SITE-PAYPAL-HOOK]   challenge-exempt **/sevenx_paypal/webhook
expect POST /sevenx_paypal/webhook                                                 answered
expect POST /sevenx_paypal/shop/create header Origin:https://example.com with pass  answered
expect POST /sevenx_paypal/shop/create header Origin:https://evil.example with pass 403 by EXP-ORIGIN
```

Run the library's `check` and `test` afterwards. Any other firewall or WAF in front of the site must let
`POST /sevenx_paypal/webhook` through without a browser check.

## 7. Show the buttons in the shop

Set `ShopCheckout=enabled` and offer PayPal to test users first (`Enabled=testers`, `TestUsers[]`), see
[configuration.md](configuration.md). The shop's card gateway template includes the buttons with:

```
{if ezini_hasvariable( 'PayPalSettings', 'ShopCheckout', 'paypal.ini' )}{if eq( ezini( 'PayPalSettings', 'ShopCheckout', 'paypal.ini' ), 'enabled' )}
{include uri='design:sevenx_paypal/shop_buttons.tpl' order_id=$order_id}
{/if}{/if}
```

(after the card form of `workflow/eventtype/result/<gateway>_form.tpl`). A shop without a card gateway selects the
gateway `Sevenx_PayPal` in its payment workflow event instead ([checkout.md](checkout.md)).

## Uninstall

Remove the line from `ActiveExtensions[]`, regenerate the autoloads, clear the caches. The tables can stay (they are
the record of the payments) or be dropped by hand. The webhook stays in the PayPal app until you delete it in the
Developer Dashboard.
