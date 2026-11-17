-- sevenx_paypal (doc/install.md): the PayPal orders this site created and the webhook events it received.
-- Ids, amounts (minor units) and states only; never a payer's name, e-mail or address.
CREATE SEQUENCE sevenx_paypal_transaction_s START 1 INCREMENT 1 MAXVALUE 9223372036854775807 MINVALUE 1 CACHE 1;
CREATE TABLE sevenx_paypal_transaction (
    id integer DEFAULT nextval('sevenx_paypal_transaction_s'::text) NOT NULL,
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
    user_id integer NOT NULL DEFAULT 0,
    meta text NOT NULL DEFAULT '',
    failure varchar(255) NOT NULL DEFAULT '',
    created integer NOT NULL DEFAULT 0,
    modified integer NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX sevenx_paypal_transaction_order ON sevenx_paypal_transaction (paypal_order_id);
CREATE INDEX sevenx_paypal_transaction_ref ON sevenx_paypal_transaction (reference);
CREATE INDEX sevenx_paypal_transaction_capture ON sevenx_paypal_transaction (capture_id);
CREATE INDEX sevenx_paypal_transaction_auth ON sevenx_paypal_transaction (authorization_id);

CREATE SEQUENCE sevenx_paypal_event_s START 1 INCREMENT 1 MAXVALUE 9223372036854775807 MINVALUE 1 CACHE 1;
CREATE TABLE sevenx_paypal_event (
    id integer DEFAULT nextval('sevenx_paypal_event_s'::text) NOT NULL,
    event_id varchar(64) NOT NULL DEFAULT '',
    mode varchar(10) NOT NULL DEFAULT 'sandbox',
    event_type varchar(64) NOT NULL DEFAULT '',
    resource_type varchar(32) NOT NULL DEFAULT '',
    resource_id varchar(64) NOT NULL DEFAULT '',
    order_id varchar(64) NOT NULL DEFAULT '',
    summary varchar(255) NOT NULL DEFAULT '',
    verified smallint NOT NULL DEFAULT 0,
    received integer NOT NULL DEFAULT 0,
    processed integer NOT NULL DEFAULT 0,
    result varchar(255) NOT NULL DEFAULT '',
    attempts integer NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX sevenx_paypal_event_event ON sevenx_paypal_event (event_id);
CREATE INDEX sevenx_paypal_event_order ON sevenx_paypal_event (order_id);
