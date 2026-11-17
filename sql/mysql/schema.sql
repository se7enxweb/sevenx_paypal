-- sevenx_paypal (doc/install.md): the PayPal orders this site created and the webhook events it received.
-- Ids, amounts (minor units) and states only; never a payer's name, e-mail or address. MySQL and MariaDB.
CREATE TABLE sevenx_paypal_transaction (
    id int(11) NOT NULL AUTO_INCREMENT,
    paypal_order_id varchar(64) NOT NULL DEFAULT '',
    mode varchar(10) NOT NULL DEFAULT 'sandbox',
    context varchar(32) NOT NULL DEFAULT '',
    reference varchar(128) NOT NULL DEFAULT '',
    custom_id varchar(127) NOT NULL DEFAULT '',
    intent varchar(10) NOT NULL DEFAULT 'CAPTURE',
    amount_minor bigint NOT NULL DEFAULT 0,
    currency char(3) NOT NULL DEFAULT 'USD',
    status varchar(32) NOT NULL DEFAULT 'CREATED',
    capture_id varchar(64) NOT NULL DEFAULT '',
    authorization_id varchar(64) NOT NULL DEFAULT '',
    refunded_minor bigint NOT NULL DEFAULT 0,
    user_id int(11) NOT NULL DEFAULT 0,
    meta text NOT NULL,
    failure varchar(255) NOT NULL DEFAULT '',
    created int(11) NOT NULL DEFAULT 0,
    modified int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY sevenx_paypal_transaction_order (paypal_order_id),
    KEY sevenx_paypal_transaction_ref (reference),
    KEY sevenx_paypal_transaction_capture (capture_id),
    KEY sevenx_paypal_transaction_auth (authorization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sevenx_paypal_event (
    id int(11) NOT NULL AUTO_INCREMENT,
    event_id varchar(64) NOT NULL DEFAULT '',
    mode varchar(10) NOT NULL DEFAULT 'sandbox',
    event_type varchar(64) NOT NULL DEFAULT '',
    resource_type varchar(32) NOT NULL DEFAULT '',
    resource_id varchar(64) NOT NULL DEFAULT '',
    order_id varchar(64) NOT NULL DEFAULT '',
    summary varchar(255) NOT NULL DEFAULT '',
    verified smallint NOT NULL DEFAULT 0,
    received int(11) NOT NULL DEFAULT 0,
    processed int(11) NOT NULL DEFAULT 0,
    result varchar(255) NOT NULL DEFAULT '',
    attempts int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY sevenx_paypal_event_event (event_id),
    KEY sevenx_paypal_event_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
