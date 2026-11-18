# App API (mobile apps)

The server creates and captures; the app only shows PayPal's approval with PayPal's own SDK, so no PayPal secret and
no card data are ever in the app.

## The flow

1. The app asks your server for a payment of something the server prices (Katalyst: `POST /katalyst/api/v1/checkout`
   with `"gateway": "paypal"`). The server calls `sevenxPayPalPayments::begin()` and answers:
   - `paypal_order_id`: the Orders v2 order;
   - `paypal_client_id`: the app's PayPal client id (public by design);
   - `paypal_mode`: `sandbox` or `live` (the SDK's environment);
   - `paypal_approve_url`: a browser fallback (may be null).
2. The app starts PayPal's checkout with that order id: its `createOrder` callback returns `paypal_order_id`; the app
   never creates an order and never sends an amount.
3. On approval the app tells the server (Katalyst: `POST /katalyst/api/v1/checkout/<order>/complete` with
   `{"paypal_order_id": "..."}`). The server captures (`capture()`), checks, completes the order and answers its state:
   `paid`, `processing` (pending at PayPal; the webhook completes it), `requires_payment` (declined or cancelled:
   nothing charged) or `test_payment_verified` (a sandbox capture that does not complete orders here).
4. On cancel in the SDK nothing is charged; the app may start again.

## iOS

- PayPal's iOS SDK (`PayPal` Swift package, module `PayPalWebPayments` or the Mobile Checkout SDK `PayPalCheckout`).
- Configure it with `paypal_client_id` and the environment from `paypal_mode` (`.sandbox` or `.live`).
- Start the checkout with the server's `paypal_order_id`; on `onApprove` call your complete route; on `onCancel` and
  `onError` keep the order unpaid and show a message.
- Use a return URL scheme only if your SDK flow needs one; the server's order does not depend on it.

## Android

- PayPal's Android SDK (`com.paypal.android:paypal-web-payments` or the Mobile Checkout SDK
  `com.paypal.checkout:android-sdk`).
- The same: client id and environment from the server, `createOrder` returns the server's order id, `onApprove` calls
  the complete route.

## Testing

Sandbox: the server in `Mode=sandbox`, a sandbox personal account or the guest card form on the sandbox approval
page ([paypal-account-setup.md](paypal-account-setup.md)). The server completes sandbox orders only with
`AcceptSandboxPayments=enabled` (or the app API's own test switch).
