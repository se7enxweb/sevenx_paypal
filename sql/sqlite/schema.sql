-- sevenx_paypal (doc/install.md): the PayPal orders this site created and the webhook events it received. SQLite 3.
CREATE TABLE sevenx_paypal_transaction (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    paypal_order_id TEXT NOT NULL DEFAULT '', mode TEXT NOT NULL DEFAULT 'sandbox', context TEXT NOT NULL DEFAULT '',
    reference TEXT NOT NULL DEFAULT '', custom_id TEXT NOT NULL DEFAULT '', intent TEXT NOT NULL DEFAULT 'CAPTURE',
    amount_minor INTEGER NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT 'USD', status TEXT NOT NULL DEFAULT 'CREATED',
    capture_id TEXT NOT NULL DEFAULT '', authorization_id TEXT NOT NULL DEFAULT '', refunded_minor INTEGER NOT NULL DEFAULT 0,
    user_id INTEGER NOT NULL DEFAULT 0, meta TEXT NOT NULL DEFAULT '', failure TEXT NOT NULL DEFAULT '',
    created INTEGER NOT NULL DEFAULT 0, modified INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX sevenx_paypal_transaction_order ON sevenx_paypal_transaction (paypal_order_id);
CREATE INDEX sevenx_paypal_transaction_ref ON sevenx_paypal_transaction (reference);
CREATE INDEX sevenx_paypal_transaction_capture ON sevenx_paypal_transaction (capture_id);
CREATE INDEX sevenx_paypal_transaction_auth ON sevenx_paypal_transaction (authorization_id);
CREATE TABLE sevenx_paypal_event (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL DEFAULT '', mode TEXT NOT NULL DEFAULT 'sandbox', event_type TEXT NOT NULL DEFAULT '',
    resource_type TEXT NOT NULL DEFAULT '', resource_id TEXT NOT NULL DEFAULT '', order_id TEXT NOT NULL DEFAULT '',
    summary TEXT NOT NULL DEFAULT '', verified INTEGER NOT NULL DEFAULT 0, received INTEGER NOT NULL DEFAULT 0,
    processed INTEGER NOT NULL DEFAULT 0, result TEXT NOT NULL DEFAULT '', attempts INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX sevenx_paypal_event_event ON sevenx_paypal_event (event_id);
CREATE INDEX sevenx_paypal_event_order ON sevenx_paypal_event (order_id);
-- 1.1: the saved payment methods (Vault v3): PayPal's token and customer ids, a masked label; never card data.
CREATE TABLE sevenx_paypal_vault (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL DEFAULT 0, mode TEXT NOT NULL DEFAULT '', customer_id TEXT NOT NULL DEFAULT '',
    payment_token_id TEXT NOT NULL DEFAULT '', method TEXT NOT NULL DEFAULT '', label TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT '', usage_type TEXT NOT NULL DEFAULT '', context TEXT NOT NULL DEFAULT '',
    created INTEGER NOT NULL DEFAULT 0, modified INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX sxpp_vault_token ON sevenx_paypal_vault (payment_token_id);
CREATE INDEX sxpp_vault_user ON sevenx_paypal_vault (user_id, status);
CREATE INDEX sxpp_vault_customer ON sevenx_paypal_vault (customer_id);
