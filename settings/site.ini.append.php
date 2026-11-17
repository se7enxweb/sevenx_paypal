<?php /* #?ini charset="utf-8"?

[RoleSettings]
# PayPal's webhook deliveries (doc/webhooks.md): POST only, no sign-in; PayPal's signature is the credential.
PolicyOmitList[]=sevenx_paypal/webhook
# The shop's PayPal buttons (doc/checkout.md): JSON create and capture of the order of the current checkout; the
# order must be the session's and the current user's, as on the shop's own checkout.
PolicyOmitList[]=sevenx_paypal/shop

*/ ?>
