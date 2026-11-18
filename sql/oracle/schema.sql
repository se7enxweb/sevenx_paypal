-- sevenx_paypal: its tables on oracle (doc/install.md "The tables").
-- Generated from sevenxPayPalSchema::definition() by paypal.php --write-schema-files with the kernel's schema handler;
-- paypal.php --install-schema creates the same tables in a running installation.

BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_sevenx_paypal_transaction';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/

CREATE TABLE sevenx_paypal_transaction (
  id INTEGER NOT NULL,
  paypal_order_id VARCHAR2(64),
  "MODE" VARCHAR2(10),
  context VARCHAR2(32),
  reference VARCHAR2(128),
  custom_id VARCHAR2(127),
  intent VARCHAR2(10),
  amount_minor INTEGER DEFAULT 0 NOT NULL,
  currency VARCHAR2(3),
  status VARCHAR2(32),
  capture_id VARCHAR2(64),
  authorization_id VARCHAR2(64),
  refunded_minor INTEGER DEFAULT 0 NOT NULL,
  user_id INTEGER DEFAULT 0 NOT NULL,
  meta CLOB,
  failure VARCHAR2(255),
  created INTEGER DEFAULT 0 NOT NULL,
  modified INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER sevenx_paypal_transac_3c0d1_tr
BEFORE INSERT ON sevenx_paypal_transaction FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_sevenx_paypal_transaction.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX sxpp_tx_auth ON sevenx_paypal_transaction ( authorization_id );

CREATE INDEX sxpp_tx_capture ON sevenx_paypal_transaction ( capture_id );

CREATE UNIQUE INDEX sxpp_tx_order ON sevenx_paypal_transaction ( paypal_order_id );

CREATE INDEX sxpp_tx_ref ON sevenx_paypal_transaction ( reference );

BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_sevenx_paypal_event';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/

CREATE TABLE sevenx_paypal_event (
  id INTEGER NOT NULL,
  event_id VARCHAR2(64),
  "MODE" VARCHAR2(10),
  event_type VARCHAR2(64),
  resource_type VARCHAR2(32),
  resource_id VARCHAR2(64),
  order_id VARCHAR2(64),
  summary VARCHAR2(255),
  verified INTEGER DEFAULT 0 NOT NULL,
  received INTEGER DEFAULT 0 NOT NULL,
  processed INTEGER DEFAULT 0 NOT NULL,
  result VARCHAR2(255),
  attempts INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER sevenx_paypal_event_id_tr
BEFORE INSERT ON sevenx_paypal_event FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_sevenx_paypal_event.nextval INTO :new.id FROM dual;
END;
/

CREATE UNIQUE INDEX sxpp_ev_event ON sevenx_paypal_event ( event_id );

CREATE INDEX sxpp_ev_order ON sevenx_paypal_event ( order_id );

BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_sevenx_paypal_vault';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/

CREATE TABLE sevenx_paypal_vault (
  id INTEGER NOT NULL,
  user_id INTEGER DEFAULT 0 NOT NULL,
  "MODE" VARCHAR2(10),
  customer_id VARCHAR2(64),
  payment_token_id VARCHAR2(64),
  method VARCHAR2(16),
  label VARCHAR2(128),
  status VARCHAR2(16),
  usage_type VARCHAR2(16),
  context VARCHAR2(32),
  created INTEGER DEFAULT 0 NOT NULL,
  modified INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER sevenx_paypal_vault_id_tr
BEFORE INSERT ON sevenx_paypal_vault FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_sevenx_paypal_vault.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX sxpp_vault_customer ON sevenx_paypal_vault ( customer_id );

CREATE UNIQUE INDEX sxpp_vault_token ON sevenx_paypal_vault ( payment_token_id );

CREATE INDEX sxpp_vault_user ON sevenx_paypal_vault ( user_id, status );

