# FAQ

**Does it replace my card gateway?**
No. The buttons appear next to it; the card form stays the default. A shop can also offer PayPal alone.

**Do I need ezpaypal?**
No. sevenx_paypal uses PayPal's REST APIs only; ezpaypal (IPN) can stay installed or be removed.

**Which payment methods do buyers see?**
PayPal, Pay Later and Debit or Credit Card, as far as PayPal offers them to the buyer and your account. Venmo can be
asked for with `EnableFunding[]=venmo` (US). `DisableFunding[]` hides any of them.

**Can I try it without real money?**
Yes: `Mode=sandbox`, the sandbox app's credentials, `Enabled=testers` with your own user id. See
[testing.md](testing.md).

**Can buyers see PayPal while I test?**
Not with `Enabled=testers`: only the users of `TestUsers[]` see it.

**Where are the credentials?**
In the secret store (Setup > Secrets). The INI files only name the entries.

**Can the PayPal app be shared with another system (a billing system)?**
Yes. sevenx_paypal creates its own webhook for its own URL and never changes the others. Note that every webhook of the
app receives every event of the app; events of payments sevenx_paypal did not make are stored and ignored
("unknown").

**What happens if the buyer closes the browser right after approving?**
The order is approved but not captured; nothing is charged. If the capture happened and only the page was lost,
`PAYMENT.CAPTURE.COMPLETED` arrives by webhook and the order is marked paid.

**Can I refund from PayPal's website?**
Yes; the webhook records the refund here.

**Which currencies?**
Any currency PayPal supports for your account. Amounts are kept in the currency's minor unit; HUF, JPY and TWD have
no decimals at PayPal.

**Does it save PayPal accounts for later (vault)?**
Yes, since 1.1.0 (Vault v3, `Vault=enabled`): at checkout, on `sevenx_paypal/methods`, and charged later by an
extension such as Katalyst's renewals. Only PayPal's token and a masked label are stored ([vault.md](vault.md)).

**Does it work with the request shield?**
Yes, with the rules of [install.md](install.md).
