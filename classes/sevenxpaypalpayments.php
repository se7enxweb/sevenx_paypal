<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * The payment flows every caller shares (the shop, Katalyst, an app): begin an order, capture or authorize it after
 * the buyer approved it, capture or void a held authorization, refund a capture. Each step is written to
 * sevenx_paypal_transaction and is safe to repeat:
 *
 *   - an order is created with a PayPal-Request-Id derived from what it pays, and an open order of the same reference
 *     and amount is reused for an hour (a reload never makes a second order);
 *   - a capture of a captured order reads the capture again instead of failing (ORDER_ALREADY_CAPTURED);
 *   - every capture and authorization passes the amount check (status, custom_id, currency, amount) against the
 *     row, which holds the amount the SERVER computed when the order was created; nothing from the browser counts.
 *
 * Methods answer ok, message and their fields; PayPal's errors become ok=false with the issue code, nothing throws
 * except a missing configuration (sevenxPayPalError 'not_configured').
 */
class sevenxPayPalPayments
{
    const T = 'sevenx_paypal_transaction';
    const REUSE_SECONDS = 3600;

    /** @var sevenxPayPalClient */
    protected $client;
    /** @var sevenxPayPalStore */
    protected $store;
    /** @var int|null the time (tests) */
    public $now = null;

    public function __construct( sevenxPayPalClient $client, ?sevenxPayPalStore $store = null )
    {
        $this->client = $client;
        $this->store = $store ?: new sevenxPayPalDbStore();
    }

    /** The service of the configured mode (or of $mode). @throws sevenxPayPalError when PayPal is not set up */
    public static function instance( $mode = null )
    {
        return new self( sevenxPayPalClient::fromSettings( $mode ) );
    }

    public function client()
    {
        return $this->client;
    }

    public function store()
    {
        return $this->store;
    }

    protected function now()
    {
        return $this->now !== null ? (int)$this->now : time();
    }

    protected static function ok( $message, array $extra = array() )
    {
        return array( 'ok' => true, 'message' => (string)$message ) + $extra;
    }

    protected static function fail( $message, array $extra = array() )
    {
        return array( 'ok' => false, 'message' => (string)$message ) + $extra;
    }

    protected static function failFrom( sevenxPayPalError $e, $what )
    {
        return self::fail( $what . ': ' . $e->getMessage(), array( 'issue' => $e->issue !== '' ? $e->issue : $e->name, 'debug_id' => $e->debugId,
                                                                     'retry_buyer' => $e->buyerCanRetry() ) );
    }

    /** The row of a PayPal order id, or null. */
    public function transaction( $paypalOrderId )
    {
        $r = $this->store->find( self::T, array( 'paypal_order_id' => (string)$paypalOrderId ), 'id', 1 );
        return $r ? $r[0] : null;
    }

    /** The row of a capture or authorization id, or null. */
    public function transactionOfPayment( $id )
    {
        foreach ( array( 'capture_id', 'authorization_id' ) as $col )
        {
            $r = $this->store->find( self::T, array( $col => (string)$id ), 'id', 1 );
            if ( $r )
                return $r[0];
        }
        return null;
    }

    /**
     * A new order for something the server priced.
     *
     * @param array $o context (shop, katalyst, app, ...), reference (what it pays, e.g. shop-order:12), custom_id
     *                 (sent to PayPal; defaults to the reference), amount_minor, currency, intent (CAPTURE|AUTHORIZE),
     *                 description, invoice_id, user_id, meta (array, kept here only), return_url, cancel_url,
     *                 payment_source (a vaulted source; a card only in sandbox mode, for the end-to-end tests)
     * @return array ok, order_id, approve_url, transaction_id, reused
     */
    public function begin( array $o )
    {
        $cfg = $this->client->config();
        $minor = (int)$o['amount_minor'];
        $cur = strtoupper( isset( $o['currency'] ) && (string)$o['currency'] !== '' ? (string)$o['currency'] : $cfg->currency() );
        $intent = isset( $o['intent'] ) && strtoupper( (string)$o['intent'] ) === 'AUTHORIZE' ? 'AUTHORIZE' : 'CAPTURE';
        $ref = substr( (string)$o['reference'], 0, 128 );
        $custom = substr( isset( $o['custom_id'] ) && (string)$o['custom_id'] !== '' ? (string)$o['custom_id'] : $ref, 0, 127 );
        if ( $minor <= 0 )
            return self::fail( 'nothing to pay' );
        if ( $ref === '' )
            return self::fail( 'an order needs a reference' );
        $source = isset( $o['payment_source'] ) && is_array( $o['payment_source'] ) ? $o['payment_source'] : array();
        // card numbers never pass through this server in live mode (the buyer types them into PayPal's own fields)
        if ( isset( $source['card']['number'] ) && $this->client->mode() === 'live' )
            return self::fail( 'a card payment source is for the sandbox only' );
        foreach ( $source ? array() : $this->store->find( self::T, array( 'reference' => $ref, 'status' => array( 'CREATED', 'APPROVED' ), 'mode' => $this->client->mode() ), 'id DESC', 5 ) as $old )
            if ( (int)$old['amount_minor'] === $minor && $old['currency'] === $cur && $old['intent'] === $intent && (int)$old['created'] > $this->now() - self::REUSE_SECONDS
                 && (int)$old['user_id'] === (int)( isset( $o['user_id'] ) ? $o['user_id'] : 0 ) )
                return self::ok( 'order ready', array( 'order_id' => $old['paypal_order_id'], 'approve_url' => '', 'transaction_id' => (int)$old['id'], 'reused' => true ) );
        $requestId = isset( $o['request_id'] ) && (string)$o['request_id'] !== '' ? (string)$o['request_id']
                   : 'sxpp-' . substr( hash( 'sha256', $this->client->mode() . '|' . $ref . '|' . $minor . '|' . $cur . '|' . $intent . '|' . bin2hex( random_bytes( 8 ) ) ), 0, 40 );
        try
        {
            $order = $this->client->createOrder( array( 'intent' => $intent, 'amount_minor' => $minor, 'currency' => $cur, 'reference_id' => substr( $ref, 0, 256 ),
                                                        'custom_id' => $custom, 'invoice_id' => isset( $o['invoice_id'] ) ? (string)$o['invoice_id'] : '',
                                                        'description' => isset( $o['description'] ) ? (string)$o['description'] : '',
                                                        'brand_name' => $cfg->brandName(), 'return_url' => isset( $o['return_url'] ) ? $o['return_url'] : '',
                                                        'cancel_url' => isset( $o['cancel_url'] ) ? $o['cancel_url'] : '', 'payment_source' => $source ), $requestId );
        }
        catch ( sevenxPayPalError $e )
        {
            self::log( 'create refused ref=' . $ref . ' issue=' . $e->issue . ' debug=' . $e->debugId );
            return self::failFrom( $e, 'PayPal did not create the order' );
        }
        $id = isset( $order['id'] ) ? (string)$order['id'] : '';
        if ( $id === '' )
            return self::fail( 'PayPal answered without an order id' );
        $tid = $this->store->insert( self::T, array( 'paypal_order_id' => $id, 'mode' => $this->client->mode(), 'context' => substr( (string)( isset( $o['context'] ) ? $o['context'] : 'other' ), 0, 32 ),
            'reference' => $ref, 'custom_id' => $custom, 'intent' => $intent, 'amount_minor' => $minor, 'currency' => $cur, 'status' => 'CREATED', 'capture_id' => '',
            'authorization_id' => '', 'refunded_minor' => 0, 'user_id' => (int)( isset( $o['user_id'] ) ? $o['user_id'] : 0 ),
            'meta' => json_encode( isset( $o['meta'] ) ? (array)$o['meta'] : array(), JSON_UNESCAPED_SLASHES ), 'failure' => '', 'created' => $this->now(), 'modified' => $this->now() ) );
        self::log( 'created order=' . $id . ' ref=' . $ref . ' minor=' . $minor . ' ' . $cur . ' intent=' . $intent . ' mode=' . $this->client->mode() );
        return self::ok( 'order ready', array( 'order_id' => $id, 'approve_url' => sevenxPayPalClient::approveUrl( $order ), 'transaction_id' => $tid, 'reused' => false ) );
    }

    /**
     * Captures an approved CAPTURE order and checks the capture against the row.
     *
     * @param string $expectedReference the reference the caller expects the order to pay ('' skips the check)
     * @return array ok, status (COMPLETED, PENDING, ...), capture_id, amount_minor, currency, transaction, already (bool),
     *               mismatch (reason) when the capture does not match
     */
    public function capture( $paypalOrderId, $expectedReference = '' )
    {
        $row = $this->transaction( $paypalOrderId );
        if ( !$row || ( $expectedReference !== '' && $row['reference'] !== (string)$expectedReference ) )
            return self::fail( 'not found', array( 'not_found' => true ) );
        if ( $row['intent'] !== 'CAPTURE' )
            return self::fail( 'this order holds money (AUTHORIZE); it is authorized, not captured' );
        if ( $row['status'] === 'COMPLETED' || in_array( $row['status'], array( 'REFUNDED', 'PARTIALLY_REFUNDED' ), true ) )
            return self::ok( 'captured already', array( 'status' => 'COMPLETED', 'capture_id' => $row['capture_id'], 'amount_minor' => (int)$row['amount_minor'],
                                                        'currency' => $row['currency'], 'transaction' => $row, 'already' => true ) );
        try
        {
            $order = $this->client->captureOrder( $paypalOrderId, 'sxpp-cap-' . $paypalOrderId );
        }
        catch ( sevenxPayPalError $e )
        {
            if ( $e->issue !== 'ORDER_ALREADY_CAPTURED' )
            {
                $this->store->update( self::T, $row['id'], array( 'failure' => substr( $e->issue !== '' ? $e->issue : $e->name, 0, 255 ), 'modified' => $this->now() ) );
                self::log( 'capture refused order=' . $paypalOrderId . ' issue=' . $e->issue . ' debug=' . $e->debugId );
                return self::failFrom( $e, 'PayPal did not take the payment' );
            }
            try
            {
                $order = $this->client->getOrder( $paypalOrderId );
            }
            catch ( sevenxPayPalError $e2 )
            {
                return self::failFrom( $e2, 'PayPal could not be asked' );
            }
        }
        // the order as PayPal answered it goes along (a saved method's token is in its payment_source)
        return $this->applyCapture( $row, sevenxPayPalClient::captureOf( $order ) ) + array( 'paypal_order' => $order );
    }

    /**
     * A capture (from capture() or a webhook) checked against its row and written: COMPLETED and matching is paid;
     * PENDING waits for PAYMENT.CAPTURE.COMPLETED; anything else is not paid. Idempotent.
     */
    public function applyCapture( array $row, ?array $cap )
    {
        $status = $cap && isset( $cap['status'] ) ? (string)$cap['status'] : '';
        $capId = $cap && isset( $cap['id'] ) ? (string)$cap['id'] : '';
        if ( $status === 'PENDING' )
        {
            $this->store->update( self::T, $row['id'], array( 'status' => 'PENDING', 'capture_id' => $capId, 'modified' => $this->now() ) );
            return self::ok( 'the payment is pending at PayPal', array( 'status' => 'PENDING', 'capture_id' => $capId, 'pending' => true, 'transaction' => $row,
                                                                       'amount_minor' => (int)$row['amount_minor'], 'currency' => $row['currency'] ) );
        }
        // a capture that was refunded since (a late webhook delivery carries the capture's state of now) was captured all
        // the same; a row that is paid or refunded is never set back
        $why = sevenxPayPalClient::mismatch( $cap, $row['custom_id'], (int)$row['amount_minor'], $row['currency'], array( 'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' ) );
        if ( $why !== '' && in_array( $row['status'], array( 'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' ), true ) )
            return self::fail( 'the payment does not match (' . $why . '); the row stays ' . $row['status'], array( 'mismatch' => $why, 'status' => $status, 'capture_id' => $capId ) );
        if ( $why !== '' )
        {
            $this->store->update( self::T, $row['id'], array( 'status' => $status === 'COMPLETED' ? 'MISMATCH' : 'FAILED', 'capture_id' => $capId,
                                                              'failure' => $why . ( $status !== '' ? ' (' . $status . ')' : '' ), 'modified' => $this->now() ) );
            self::log( 'capture refused order=' . $row['paypal_order_id'] . ' reason=' . $why . ' status=' . $status );
            return self::fail( 'the payment does not match (' . $why . ')', array( 'mismatch' => $why, 'status' => $status, 'capture_id' => $capId ) );
        }
        $already = in_array( $row['status'], array( 'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' ), true );
        $state = (int)$row['refunded_minor'] <= 0 ? 'COMPLETED' : ( (int)$row['refunded_minor'] >= (int)$row['amount_minor'] ? 'REFUNDED' : 'PARTIALLY_REFUNDED' );
        $this->store->update( self::T, $row['id'], array( 'status' => $state, 'capture_id' => $capId, 'failure' => '', 'modified' => $this->now() ) );
        if ( !$already )
            self::log( 'captured order=' . $row['paypal_order_id'] . ' capture=' . $capId . ' ref=' . $row['reference'] . ' minor=' . $row['amount_minor'] . ' ' . $row['currency'] );
        return self::ok( 'paid', array( 'status' => 'COMPLETED', 'capture_id' => $capId, 'amount_minor' => (int)$row['amount_minor'], 'currency' => $row['currency'],
                                        'transaction' => $this->store->get( self::T, $row['id'] ), 'already' => $already ) );
    }

    /**
     * Authorizes an approved AUTHORIZE order: the amount is held on the buyer's PayPal account, nothing is taken.
     *
     * @return array ok, authorization_id, status (CREATED), expires
     */
    public function authorize( $paypalOrderId, $expectedReference = '' )
    {
        $row = $this->transaction( $paypalOrderId );
        if ( !$row || ( $expectedReference !== '' && $row['reference'] !== (string)$expectedReference ) )
            return self::fail( 'not found', array( 'not_found' => true ) );
        if ( $row['intent'] !== 'AUTHORIZE' )
            return self::fail( 'this order is paid at once (CAPTURE), it cannot hold money' );
        if ( $row['authorization_id'] !== '' && $row['status'] === 'AUTHORIZED' )
            return self::ok( 'authorized already', array( 'authorization_id' => $row['authorization_id'], 'status' => 'CREATED', 'already' => true ) );
        try
        {
            $order = $this->client->authorizeOrder( $paypalOrderId, 'sxpp-auth-' . $paypalOrderId );
        }
        catch ( sevenxPayPalError $e )
        {
            if ( $e->issue !== 'ORDER_ALREADY_AUTHORIZED' )
            {
                $this->store->update( self::T, $row['id'], array( 'failure' => substr( $e->issue !== '' ? $e->issue : $e->name, 0, 255 ), 'modified' => $this->now() ) );
                return self::failFrom( $e, 'PayPal did not hold the amount' );
            }
            $order = $this->client->getOrder( $paypalOrderId );
        }
        $auth = sevenxPayPalClient::authorizationOf( $order );
        $why = sevenxPayPalClient::mismatch( $auth, $row['custom_id'], (int)$row['amount_minor'], $row['currency'], array( 'CREATED', 'PENDING' ) );
        $authId = $auth && isset( $auth['id'] ) ? (string)$auth['id'] : '';
        if ( $why !== '' )
        {
            $this->store->update( self::T, $row['id'], array( 'status' => 'MISMATCH', 'authorization_id' => $authId, 'failure' => $why, 'modified' => $this->now() ) );
            if ( $authId !== '' )
                $this->void( $authId );
            return self::fail( 'the authorization does not match (' . $why . ')', array( 'mismatch' => $why ) );
        }
        $this->store->update( self::T, $row['id'], array( 'status' => 'AUTHORIZED', 'authorization_id' => $authId, 'failure' => '', 'modified' => $this->now() ) );
        self::log( 'authorized order=' . $paypalOrderId . ' authorization=' . $authId . ' ref=' . $row['reference'] );
        return self::ok( 'the amount is held', array( 'authorization_id' => $authId, 'status' => (string)$auth['status'],
                                                     'expires' => isset( $auth['expiration_time'] ) ? (string)$auth['expiration_time'] : '' ) );
    }

    /** The state of a held authorization: held (CREATED/PENDING), captured, voided. */
    public function authorizationState( $authId )
    {
        try
        {
            $a = $this->client->getAuthorization( $authId );
        }
        catch ( sevenxPayPalError $e )
        {
            return self::failFrom( $e, 'PayPal could not be asked' ) + array( 'status' => 'unknown' );
        }
        $s = isset( $a['status'] ) ? (string)$a['status'] : '';
        return self::ok( 'the authorization is ' . $s, array( 'status' => $s, 'held' => in_array( $s, array( 'CREATED', 'PENDING' ), true ),
                                                             'captured' => in_array( $s, array( 'CAPTURED', 'PARTIALLY_CAPTURED' ), true ),
                                                             'voided' => in_array( $s, array( 'VOIDED', 'EXPIRED', 'DENIED' ), true ) ) );
    }

    /** Takes a held amount (all of it). @return array ok, capture_id, status */
    public function captureAuthorization( $authId, $invoiceId = '' )
    {
        $row = $this->transactionOfPayment( $authId );
        if ( !$row )
            return self::fail( 'not found', array( 'not_found' => true ) );
        if ( $row['status'] === 'COMPLETED' && $row['capture_id'] !== '' )
            return self::ok( 'captured already', array( 'capture_id' => $row['capture_id'], 'status' => 'COMPLETED', 'already' => true ) );
        try
        {
            $cap = $this->client->captureAuthorization( $authId, null, '', $invoiceId, 'sxpp-authcap-' . $authId );
        }
        catch ( sevenxPayPalError $e )
        {
            $st = $this->authorizationState( $authId );
            if ( !empty( $st['captured'] ) )
            {
                $o = $this->client->getOrder( $row['paypal_order_id'] );
                $cap = sevenxPayPalClient::captureOf( $o );
            }
            else
                return self::failFrom( $e, 'PayPal did not take the held amount' );
        }
        if ( $cap && !isset( $cap['custom_id'] ) )
            $cap['custom_id'] = $row['custom_id'];
        $r = $this->applyCapture( $row, $cap );
        return $r;
    }

    /** Releases a held amount; idempotent (a voided or expired authorization is fine). */
    public function void( $authId )
    {
        try
        {
            $this->client->voidAuthorization( $authId, 'sxpp-void-' . $authId );
        }
        catch ( sevenxPayPalError $e )
        {
            $st = $this->authorizationState( $authId );
            if ( empty( $st['voided'] ) )
                return self::failFrom( $e, 'PayPal did not release the hold' );
        }
        if ( $row = $this->transactionOfPayment( $authId ) )
            $this->store->update( self::T, $row['id'], array( 'status' => 'VOIDED', 'modified' => $this->now() ) );
        self::log( 'voided authorization=' . $authId );
        return self::ok( 'the hold was released; nothing was taken', array( 'released' => true ) );
    }

    /**
     * Refunds (part of) a capture. $requestKey makes a repeat of the same refund return PayPal's first answer.
     *
     * @param int|null $minor null: all of what is left
     * @return array ok, refund_id, status (COMPLETED, PENDING), amount_minor
     */
    public function refund( $captureId, $minor = null, $currency = '', $note = '', $requestKey = '' )
    {
        $row = $this->transactionOfPayment( $captureId );
        $cur = $currency !== '' ? strtoupper( $currency ) : ( $row ? $row['currency'] : $this->client->config()->currency() );
        if ( $row )
        {
            $left = (int)$row['amount_minor'] - (int)$row['refunded_minor'];
            if ( $minor === null )
                $minor = $left;
            if ( (int)$minor <= 0 || (int)$minor > $left )
                return self::fail( 'at most ' . sevenxPayPalMoney::toValue( $left, $cur ) . ' ' . $cur . ' can be refunded' );
        }
        $key = $requestKey !== '' ? $requestKey : 'sxpp-refund-' . $captureId . '-' . ( $row ? (int)$row['refunded_minor'] : 0 ) . '-' . (int)$minor;
        try
        {
            $re = $this->client->refundCapture( $captureId, $minor === null ? null : (int)$minor, $cur, $note, '', $key );
        }
        catch ( sevenxPayPalError $e )
        {
            self::log( 'refund refused capture=' . $captureId . ' issue=' . $e->issue . ' debug=' . $e->debugId );
            return self::failFrom( $e, 'PayPal refused the refund' );
        }
        $status = isset( $re['status'] ) ? (string)$re['status'] : '';
        if ( !in_array( $status, array( 'COMPLETED', 'PENDING' ), true ) )
            return self::fail( 'the refund is ' . strtolower( $status ), array( 'status' => $status ) );
        $got = isset( $re['amount']['value'] ) ? sevenxPayPalMoney::toMinor( $re['amount']['value'], $cur ) : $minor;
        if ( $row )
        {
            $total = (int)$row['refunded_minor'] + (int)$got;
            $this->store->update( self::T, $row['id'], array( 'refunded_minor' => $total, 'modified' => $this->now(),
                                                              'status' => $total >= (int)$row['amount_minor'] ? 'REFUNDED' : 'PARTIALLY_REFUNDED' ) );
        }
        self::log( 'refunded capture=' . $captureId . ' refund=' . ( isset( $re['id'] ) ? $re['id'] : '?' ) . ' minor=' . (int)$got . ' status=' . $status );
        return self::ok( 'refunded', array( 'refund_id' => isset( $re['id'] ) ? (string)$re['id'] : '', 'status' => $status, 'amount_minor' => (int)$got ) );
    }

    /** One line in var/log/paypal.log: ids, amounts and states only. */
    public static function log( $line )
    {
        if ( class_exists( 'eZLog' ) && class_exists( 'eZINI' ) )
            eZLog::write( (string)$line, (string)sevenxPayPalConfig::instance()->value( 'LogFile', 'paypal.log' ) );
    }
}
