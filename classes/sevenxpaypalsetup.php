<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * Setting a site up with its PayPal app (doc/paypal-account-setup.md): the webhook URL of this site, a check of the
 * connection, and the creation of this site's webhook when the app has none for that URL. A webhook of another URL
 * (another shop or a billing system sharing the app) is never changed or deleted.
 */
class sevenxPayPalSetup
{
    /** The URL PayPal delivers to: [WebhookSettings] Url, else https://<SiteURL>/sevenx_paypal/webhook. */
    public static function webhookUrl( sevenxPayPalConfig $cfg )
    {
        if ( class_exists( 'eZINI' ) )
        {
            $ini = eZINI::instance( 'paypal.ini' );
            if ( $ini->hasVariable( 'WebhookSettings', 'Url' ) && preg_match( '#^https://#', (string)$ini->variable( 'WebhookSettings', 'Url' ) ) )
                return (string)$ini->variable( 'WebhookSettings', 'Url' );
            $site = trim( (string)eZINI::instance( 'site.ini' )->variable( 'SiteSettings', 'SiteURL' ), '/' );
            $site = preg_replace( '#^https?://#', '', $site );
            if ( $site !== '' )
                return 'https://' . $site . '/sevenx_paypal/webhook';
        }
        return '';
    }

    /**
     * The connection and the webhook as PayPal sees them.
     *
     * @return array app_id, webhook_found (bool), webhook_id, webhook_events (int), stored_id_matches (bool), webhooks (int)
     */
    public static function check( sevenxPayPalClient $client, $url )
    {
        $client->token( true );
        $hooks = $client->listWebhooks();
        $mine = null;
        foreach ( $hooks as $w )
            if ( $url !== '' && isset( $w['url'] ) && rtrim( (string)$w['url'], '/' ) === rtrim( $url, '/' ) )
                $mine = $w;
        $names = array();
        foreach ( $mine && isset( $mine['event_types'] ) ? $mine['event_types'] : array() as $e )
            $names[] = (string)$e['name'];
        return array( 'app_id' => $client->appId, 'webhooks' => count( $hooks ), 'webhook_found' => (bool)$mine, 'webhook_id' => $mine ? (string)$mine['id'] : '',
                      'webhook_events' => count( $names ), 'missing_events' => array_values( array_diff( sevenxPayPalClient::WEBHOOK_EVENTS, $names ) ),
                      'stored_id_matches' => $mine && (string)$mine['id'] === $client->config()->webhookId );
    }

    /**
     * Creates this site's webhook if the app has none for $url, and stores its id in the secret store under the
     * mode's name ([PayPalSettings] <Mode>WebhookIdSecret). An existing one is only adopted (its id stored).
     *
     * @return array ok, message, webhook_id, created (bool)
     */
    public static function ensureWebhook( sevenxPayPalClient $client, $url, $store = true )
    {
        if ( $url === '' || !preg_match( '#^https://#', $url ) )
            return array( 'ok' => false, 'message' => 'The webhook URL is not known: set [WebhookSettings] Url in paypal.ini.' );
        $existing = $client->findWebhook( $url );
        $created = false;
        if ( !$existing )
        {
            $existing = $client->createWebhook( $url );
            $created = true;
        }
        $id = isset( $existing['id'] ) ? (string)$existing['id'] : '';
        if ( $id === '' )
            return array( 'ok' => false, 'message' => 'PayPal answered without a webhook id.' );
        $saved = false;
        $names = $client->config()->secretNames();
        if ( $store && class_exists( 'expSecrets' ) && $names['webhook_id'] !== '' )
            $saved = (bool)expSecrets::set( $names['webhook_id'], $id, 'paypal', 'PayPal ' . $client->mode() . ' webhook id of ' . $url . ' (not secret; kept with the credentials)' );
        return array( 'ok' => true, 'created' => $created, 'webhook_id' => $id, 'stored' => $saved,
                      'message' => ( $created ? 'The webhook was created' : 'The webhook exists already' ) . ' (' . $id . ')' . ( $saved ? ' and its id is stored.' : '.' ) );
    }

    /** The engine of the site's database: postgresql, mysql or sqlite. */
    public static function engine()
    {
        $impl = strtolower( (string)eZINI::instance()->variable( 'DatabaseSettings', 'DatabaseImplementation' ) );
        if ( strpos( $impl, 'postgres' ) !== false || $impl === 'pgsql' )
            return 'postgresql';
        if ( strpos( $impl, 'mysql' ) !== false )
            return 'mysql';
        return 'sqlite';
    }

    /** The extension's tables the database has (lower case names). */
    public static function tables()
    {
        $db = eZDB::instance();
        $engine = self::engine();
        $sql = $engine === 'postgresql' ? "SELECT tablename AS name FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE 'sevenx_paypal%'"
             : ( $engine === 'mysql' ? "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'sevenx_paypal%'"
                                     : "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'sevenx_paypal%'" );
        $rows = $db->arrayQuery( $sql );
        $out = array();
        foreach ( is_array( $rows ) ? $rows : array() as $r )
            $out[] = strtolower( (string)reset( $r ) );
        return $out;
    }

    /**
     * Creates the tables of sql/<engine>/schema.sql that the database lacks (with their sequences and indexes);
     * existing tables are left alone. @return array ok, lines
     */
    public static function installSchema( $sqlDir )
    {
        $engine = self::engine();
        $file = rtrim( $sqlDir, '/' ) . '/' . $engine . '/schema.sql';
        if ( !is_readable( $file ) )
            return array( 'ok' => false, 'lines' => array( 'no schema for ' . $engine . ' (' . $file . ')' ) );
        $db = eZDB::instance();
        $have = self::tables();
        $lines = array( 'database: ' . $engine );
        $ok = true;
        foreach ( preg_split( '/;\s*(\r?\n|$)/', (string)file_get_contents( $file ) ) as $s )
        {
            $s = trim( preg_replace( '/^\s*--.*$/m', '', $s ) );
            if ( $s === '' )
                continue;
            if ( !preg_match( '/(sevenx_paypal_(transaction|event))/', $s, $m ) )
                continue;
            $table = $m[1];
            if ( in_array( $table, $have, true ) )
                continue;
            $r = $db->query( $s );
            $lines[] = ( $r === false ? 'FAILED: ' : 'ran: ' ) . substr( preg_replace( '/\s+/', ' ', $s ), 0, 90 );
            $ok = $ok && $r !== false;
        }
        $after = self::tables();
        foreach ( array( 'sevenx_paypal_transaction', 'sevenx_paypal_event' ) as $t )
        {
            $lines[] = $t . ': ' . ( in_array( $t, $after, true ) ? ( in_array( $t, $have, true ) ? 'there already' : 'created' ) : 'MISSING' );
            $ok = $ok && in_array( $t, $after, true );
        }
        return array( 'ok' => $ok, 'lines' => $lines );
    }
}
