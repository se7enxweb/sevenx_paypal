<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * The settings of sevenx_paypal (paypal.ini [PayPalSettings], doc/configuration.md) and the credentials of the
 * configured mode. Credentials are never in an INI file: the INI names entries of the kernel's secret store
 * (expSecrets), and only the names are ever shown.
 *
 * Built from an INI reader (eZINI, or a plain array in the tests) so that every rule here is testable without the kernel.
 */
class sevenxPayPalConfig
{
    const BLOCK = 'PayPalSettings';
    const SANDBOX_API = 'https://api-m.sandbox.paypal.com';
    const LIVE_API = 'https://api-m.paypal.com';

    /** @var array|null name => value (tests) */
    protected $values;
    /** @var callable|null name => secret value (tests) */
    protected $secretLookup;

    public $mode = 'sandbox';
    public $clientId = '';
    public $secret = '';
    public $webhookId = '';
    /** @var string why the credentials cannot be used ('' when they can) */
    public $problem = '';

    /**
     * @param array|null    $values       [PayPalSettings] as name => value; null reads paypal.ini through eZINI
     * @param callable|null $secretLookup name => value; null reads expSecrets
     * @param string|null   $mode         force sandbox or live; null takes Mode
     */
    public function __construct( ?array $values = null, $secretLookup = null, $mode = null )
    {
        $this->values = $values;
        $this->secretLookup = $secretLookup;
        $m = strtolower( (string)( $mode !== null ? $mode : $this->value( 'Mode', 'sandbox' ) ) );
        $this->mode = $m;
        $this->load();
    }

    /** The configured settings of this request (siteaccess overrides apply), cached per mode. */
    public static function instance( $mode = null )
    {
        static $cache = array();
        $key = $mode === null ? '' : (string)$mode;
        if ( !isset( $cache[$key] ) )
            $cache[$key] = new self( null, null, $mode );
        return $cache[$key];
    }

    /** One setting of [PayPalSettings]. */
    public function value( $name, $default = null )
    {
        if ( $this->values !== null )
            return array_key_exists( $name, $this->values ) ? $this->values[$name] : $default;
        if ( !class_exists( 'eZINI' ) )
            return $default;
        $ini = eZINI::instance( 'paypal.ini' );
        return $ini->hasVariable( self::BLOCK, $name ) ? $ini->variable( self::BLOCK, $name ) : $default;
    }

    /** A list setting (Name[]), always an array of non-empty strings. */
    public function listValue( $name )
    {
        $v = $this->value( $name, array() );
        return array_values( array_filter( array_map( 'strval', is_array( $v ) ? $v : array( $v ) ), 'strlen' ) );
    }

    protected function secretValue( $name )
    {
        if ( $name === '' )
            return '';
        if ( $this->secretLookup )
            return (string)call_user_func( $this->secretLookup, $name );
        return class_exists( 'expSecrets' ) ? (string)expSecrets::get( $name ) : '';
    }

    /** The secret store names of a mode: client id, secret, webhook id. */
    public function secretNames( $mode = null )
    {
        $m = ( $mode === null ? $this->mode : $mode ) === 'live' ? 'Live' : 'Sandbox';
        $p = 'paypal-' . strtolower( $m ) . '-';
        return array( 'client_id' => (string)$this->value( $m . 'ClientIdSecret', $p . 'client-id' ),
                      'secret' => (string)$this->value( $m . 'SecretSecret', $p . 'secret' ),
                      'webhook_id' => (string)$this->value( $m . 'WebhookIdSecret', $p . 'webhook-id' ) );
    }

    protected function load()
    {
        if ( !in_array( $this->mode, array( 'sandbox', 'live' ), true ) )
        {
            $this->problem = '[PayPalSettings] Mode must be sandbox or live, not ' . $this->mode;
            return;
        }
        $n = $this->secretNames();
        $this->clientId = $this->secretValue( $n['client_id'] );
        $this->secret = $this->secretValue( $n['secret'] );
        $hook = (string)$this->value( ucfirst( $this->mode ) . 'WebhookId', '' );
        $this->webhookId = $hook !== '' ? $hook : $this->secretValue( $n['webhook_id'] );
        $this->problem = self::check( $this->mode, $this->clientId, $this->secret, (string)getenv( 'SEVENX_PAYPAL_TESTS' ) === '1' );
    }

    /**
     * Whether a credential pair can be used (pure). PayPal client ids and secrets are 80 characters of [A-Za-z0-9_-];
     * the check is loose on the length (PayPal has changed it before) but strict on the characters. With $testsOnly
     * (SEVENX_PAYPAL_TESTS=1 in the environment) the live mode is refused altogether.
     *
     * @return string '' or why not
     */
    public static function check( $mode, $clientId, $secret, $testsOnly = false )
    {
        if ( !in_array( $mode, array( 'sandbox', 'live' ), true ) )
            return "Mode must be sandbox or live, not $mode";
        if ( $mode === 'live' && $testsOnly )
            return 'the live mode is refused while tests run (SEVENX_PAYPAL_TESTS)';
        if ( $clientId === '' )
            return "no $mode client id in the secret store";
        if ( $secret === '' )
            return "no $mode secret in the secret store";
        if ( !preg_match( '/^[A-Za-z0-9_-]{20,128}$/D', $clientId ) )
            return "the $mode client id does not look like a PayPal client id";
        if ( !preg_match( '/^[A-Za-z0-9_-]{20,128}$/D', $secret ) )
            return "the $mode secret does not look like a PayPal secret";
        return '';
    }

    public function ok()
    {
        return $this->problem === '';
    }

    public function apiBase()
    {
        $custom = (string)$this->value( ucfirst( $this->mode ) . 'ApiBase', '' );
        if ( $custom !== '' && preg_match( '#^https://[a-z0-9.-]+\.paypal\.com$#D', $custom ) )
            return $custom;
        return $this->mode === 'live' ? self::LIVE_API : self::SANDBOX_API;
    }

    /** The currency of new orders (ISO 4217, upper case). */
    public function currency()
    {
        $c = strtoupper( trim( (string)$this->value( 'Currency', 'USD' ) ) );
        return preg_match( '/^[A-Z]{3}$/D', $c ) ? $c : 'USD';
    }

    public function brandName()
    {
        return mb_substr( trim( (string)$this->value( 'BrandName', '' ) ), 0, 127 );
    }

    /**
     * Whether PayPal is offered to a user on a siteaccess (pure given the values): Enabled=enabled for everybody,
     * Enabled=testers only for the user ids of TestUsers[], disabled for nobody; EnabledSiteAccesses[] (empty: every
     * siteaccess) narrows it to some siteaccesses. The credentials must be usable too.
     */
    public function enabledFor( $userId = 0, $siteAccess = '' )
    {
        if ( !$this->ok() )
            return false;
        $sites = $this->listValue( 'EnabledSiteAccesses' );
        if ( $sites && $siteAccess !== '' && !in_array( (string)$siteAccess, $sites, true ) )
            return false;
        $e = strtolower( (string)$this->value( 'Enabled', 'disabled' ) );
        if ( $e === 'enabled' )
            return true;
        if ( $e === 'testers' )
            return in_array( (string)(int)$userId, $this->listValue( 'TestUsers' ), true ) && (int)$userId > 0;
        return false;
    }

    /** The current request's user and siteaccess, asked of the kernel. */
    public function enabledForCurrentUser()
    {
        $uid = class_exists( 'eZUser' ) ? (int)eZUser::currentUserID() : 0;
        $sa = isset( $GLOBALS['eZCurrentAccess']['name'] ) ? (string)$GLOBALS['eZCurrentAccess']['name'] : '';
        return $this->enabledFor( $uid, $sa );
    }

    /** Whether a sandbox capture may mark an order paid (AcceptSandboxPayments; never needed in live mode). */
    public function acceptsPayment()
    {
        return $this->mode === 'live' || strtolower( (string)$this->value( 'AcceptSandboxPayments', 'disabled' ) ) === 'enabled';
    }

    /** The JS SDK URL of the buttons (public: the client id is public by design). */
    public function sdkUrl( $intent = 'capture', $currency = null )
    {
        $q = array( 'client-id' => $this->clientId, 'currency' => $currency !== null ? strtoupper( (string)$currency ) : $this->currency(),
                    'intent' => $intent === 'authorize' ? 'authorize' : 'capture', 'components' => 'buttons' );
        $enable = $this->listValue( 'EnableFunding' );
        $disable = $this->listValue( 'DisableFunding' );
        if ( $enable )
            $q['enable-funding'] = implode( ',', $enable );
        if ( $disable )
            $q['disable-funding'] = implode( ',', $disable );
        $locale = (string)$this->value( 'Locale', '' );
        if ( preg_match( '/^[a-z]{2}_[A-Z]{2}$/D', $locale ) )
            $q['locale'] = $locale;
        return 'https://www.paypal.com/sdk/js?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 );
    }

    /** For pages and the console: the mode and which credentials are there (never a value). */
    public function describe()
    {
        return array( 'mode' => $this->mode, 'client_id' => $this->clientId !== '', 'secret' => $this->secret !== '', 'webhook_id' => $this->webhookId,
                      'names' => $this->secretNames(), 'api' => $this->apiBase(), 'currency' => $this->currency(), 'problem' => $this->problem,
                      'enabled' => (string)$this->value( 'Enabled', 'disabled' ) );
    }
}
