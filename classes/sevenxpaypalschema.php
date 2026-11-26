<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * The tables of sevenx_paypal on the five databases Exponential runs on (doc/install.md "The tables"):
 *
 *   sevenx_paypal_transaction  the PayPal orders this site made (1.0)
 *   sevenx_paypal_event        the webhook events it received (1.0)
 *   sevenx_paypal_vault        the saved payment methods (Vault v3, 1.1): PayPal's payment token id and customer id per
 *                              user, a masked label; never card data
 *
 * PostgreSQL, MySQL/MariaDB and SQLite create them from sql/<database>/schema.sql (hand-written, as in 1.0); Oracle (the
 * ezoracle extension) and MongoDB (expMongoSchema) through the kernel's schema handler from definition() below, which
 * also wrote sql/oracle/schema.sql and sql/mongodb/schema.json (paypal.php --write-schema-files). Names fit Oracle's
 * 30 characters; "mode" is an Oracle reserved word: the handler quotes it and settings/ezoracle.ini.append.php makes
 * the driver quote it in every statement.
 */
class sevenxPayPalSchema
{
    const TABLES = array( 'sevenx_paypal_transaction', 'sevenx_paypal_event', 'sevenx_paypal_vault' );

    /** The kernel's generic schema of the three tables. */
    public static function definition()
    {
        $int = array( 'length' => 11, 'type' => 'int', 'not_null' => '1', 'default' => 0 );
        $v = function ( $n ) { return array( 'length' => $n, 'type' => 'varchar', 'not_null' => '1', 'default' => '' ); };
        $text = array( 'type' => 'longtext', 'default' => null );
        $t = function ( $name, array $fields, array $indexes ) {
            return array( 'name' => $name, 'fields' => array( 'id' => array( 'type' => 'auto_increment', 'default' => false ) ) + $fields,
                          'indexes' => array( 'PRIMARY' => array( 'type' => 'primary', 'fields' => array( 'id' ) ) ) + $indexes );
        };
        $idx = function ( $type ) { $f = func_get_args(); array_shift( $f ); return array( 'type' => $type, 'fields' => $f ); };
        return array(
            'sevenx_paypal_transaction' => $t( 'sevenx_paypal_transaction', array(
                'paypal_order_id' => $v( 64 ), 'mode' => $v( 10 ), 'context' => $v( 32 ), 'reference' => $v( 128 ), 'custom_id' => $v( 127 ),
                'intent' => $v( 10 ), 'amount_minor' => $int, 'currency' => $v( 3 ), 'status' => $v( 32 ), 'capture_id' => $v( 64 ),
                'authorization_id' => $v( 64 ), 'refunded_minor' => $int, 'user_id' => $int, 'meta' => $text, 'failure' => $v( 255 ),
                'created' => $int, 'modified' => $int ),
                array( 'sxpp_tx_order' => $idx( 'unique', 'paypal_order_id' ), 'sxpp_tx_ref' => $idx( 'non-unique', 'reference' ),
                       'sxpp_tx_capture' => $idx( 'non-unique', 'capture_id' ), 'sxpp_tx_auth' => $idx( 'non-unique', 'authorization_id' ) ) ),
            'sevenx_paypal_event' => $t( 'sevenx_paypal_event', array(
                'event_id' => $v( 64 ), 'mode' => $v( 10 ), 'event_type' => $v( 64 ), 'resource_type' => $v( 32 ), 'resource_id' => $v( 64 ),
                'order_id' => $v( 64 ), 'summary' => $v( 255 ), 'verified' => $int, 'received' => $int, 'processed' => $int, 'result' => $v( 255 ),
                'attempts' => $int ),
                array( 'sxpp_ev_event' => $idx( 'unique', 'event_id' ), 'sxpp_ev_order' => $idx( 'non-unique', 'order_id' ) ) ),
            'sevenx_paypal_vault' => $t( 'sevenx_paypal_vault', array(
                'user_id' => $int, 'mode' => $v( 10 ), 'customer_id' => $v( 64 ), 'payment_token_id' => $v( 64 ), 'method' => $v( 16 ),
                'label' => $v( 128 ), 'status' => $v( 16 ), 'usage_type' => $v( 16 ), 'context' => $v( 32 ), 'created' => $int, 'modified' => $int ),
                array( 'sxpp_vault_token' => $idx( 'unique', 'payment_token_id' ), 'sxpp_vault_user' => $idx( 'non-unique', 'user_id', 'status' ),
                       'sxpp_vault_customer' => $idx( 'non-unique', 'customer_id' ) ) ),
        );
    }

    /** postgresql, mysql, sqlite, oracle or mongodb, from the connection (else from site.ini). */
    public static function engine( $db = null )
    {
        $name = '';
        if ( $db || class_exists( 'eZDB' ) )
        {
            $db = $db ?: eZDB::instance();
            $name = strtolower( (string)$db->databaseName() );
        }
        if ( $name === '' && class_exists( 'eZINI' ) )
            $name = strtolower( (string)eZINI::instance()->variable( 'DatabaseSettings', 'DatabaseImplementation' ) );
        if ( strpos( $name, 'postgres' ) !== false || $name === 'pgsql' )
            return 'postgresql';
        if ( strpos( $name, 'sqlite' ) !== false )
            return 'sqlite';
        if ( strpos( $name, 'mongo' ) !== false )
            return 'mongodb';
        if ( strpos( $name, 'oracle' ) !== false )
            return 'oracle';
        return 'mysql';
    }

    /** The kernel's schema handler of an engine (Oracle: from the ezoracle extension). */
    public static function handler( $engine, array $schema, $db = null )
    {
        $classes = array( 'mysql' => 'eZMysqlSchema', 'postgresql' => 'eZPgsqlSchema', 'sqlite' => 'eZSQLiteSchema',
                          'oracle' => 'eZOracleSchema', 'mongodb' => 'expMongoSchema' );
        if ( !isset( $classes[$engine] ) )
            return null;
        $class = $classes[$engine];
        if ( !class_exists( $class ) && $engine === 'oracle' && class_exists( 'eZExtension' ) )
        {
            $file = eZExtension::baseDirectory() . '/ezoracle/ezdb/dbms-schema/ezoracleschema.php';
            if ( is_file( $file ) )
                require_once $file;
        }
        return class_exists( $class ) ? new $class( array( 'schema' => $schema, 'instance' => $db, 'type' => $engine ) ) : null;
    }

    /** The statements that create $tables (all when empty) on an SQL engine, through its schema handler. */
    public static function sqlStatements( $engine, array $tables = array() )
    {
        $schema = self::definition();
        $handler = self::handler( $engine, $schema );
        if ( !$handler || $engine === 'mongodb' )
            return array();
        $local = $schema;
        $local['_info'] = array( 'format' => 'generic' );
        $handler->transformSchema( $local, true );
        unset( $local['_info'] );
        $out = array();
        foreach ( $local as $table => $def )
        {
            if ( $tables && !in_array( $table, $tables, true ) )
                continue;
            foreach ( $handler->generateTableSQLList( $table, $def, array( 'format' => 'local', 'table_type' => $engine === 'mysql' ? 'innodb' : '' ), false ) as $st )
            {
                $st = trim( $st );
                if ( !preg_match( '/^(BEGIN\b|CREATE OR REPLACE TRIGGER\b)/i', $st ) )
                    $st = rtrim( $st, ';' );
                if ( $st !== '' )
                    $out[] = $st;
            }
        }
        return $out;
    }

    /** sql/mongodb/schema.json: the collections and their indexes. */
    public static function mongoDescription()
    {
        $collections = array();
        foreach ( self::definition() as $table => $def )
        {
            $indexes = array();
            foreach ( $def['indexes'] as $name => $index )
            {
                $keys = array();
                foreach ( $index['fields'] as $f )
                    $keys[$f] = 1;
                $o = array( 'name' => $name );
                if ( in_array( $index['type'], array( 'primary', 'unique' ), true ) )
                    $o['unique'] = true;
                $indexes[] = array( 'keys' => $keys, 'options' => $o );
            }
            $collections[] = array( 'name' => $table, 'indexes' => $indexes );
        }
        return array( 'database' => 'sevenx_paypal', 'comment' => 'sevenx_paypal on MongoDB: the collections and indexes the kernel\'s MongoDB schema handler (expMongoSchema) creates from sevenxPayPalSchema::definition(); paypal.php --install-schema does it in a running installation.',
                      'collections' => $collections );
    }

    /** The text of sql/oracle/schema.sql or sql/mongodb/schema.json (null without the engine's handler). */
    public static function schemaFileText( $engine )
    {
        if ( $engine === 'mongodb' )
            return json_encode( self::mongoDescription(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
        $st = self::sqlStatements( $engine );
        if ( !$st )
            return null;
        $out = "-- sevenx_paypal: its tables on $engine (doc/install.md \"The tables\").\n"
             . "-- Generated from sevenxPayPalSchema::definition() by paypal.php --write-schema-files with the kernel's schema handler;\n"
             . "-- paypal.php --install-schema creates the same tables in a running installation.\n\n";
        foreach ( $st as $s )
            $out .= $s . ( preg_match( '/^(BEGIN\b|CREATE OR REPLACE TRIGGER\b)/i', $s ) ? "\n/\n" : ";\n" ) . "\n";
        return $out;
    }

    /** The extension's tables that exist (each engine's own catalog). */
    public static function existingTables( $db = null )
    {
        $db = $db ?: eZDB::instance();
        $engine = self::engine( $db );
        if ( $engine === 'mongodb' )
            $names = method_exists( $db, 'listCollectionNames' ) ? (array)$db->listCollectionNames() : array();
        else
        {
            $sql = array( 'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'sevenx_paypal%'",
                          'mysql' => "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'sevenx_paypal%'",
                          'postgresql' => "SELECT tablename AS name FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE 'sevenx_paypal%'",
                          'oracle' => "SELECT LOWER(table_name) AS name FROM user_tables WHERE table_name LIKE 'SEVENX_PAYPAL%'" );
            $names = array();
            foreach ( (array)$db->arrayQuery( $sql[$engine] ) as $row )
            {
                $row = array_change_key_case( $row, CASE_LOWER );
                $names[] = isset( $row['name'] ) ? $row['name'] : reset( $row );
            }
        }
        return array_values( array_intersect( self::TABLES, array_map( 'strtolower', $names ) ) );
    }

    /**
     * Creates the missing tables: PostgreSQL, MySQL and SQLite from sql/<engine>/schema.sql (the statements that name a
     * missing table), Oracle and MongoDB through the schema handler. @return array ok, lines
     */
    public static function install( $sqlDir, $db = null )
    {
        $db = $db ?: eZDB::instance();
        $engine = self::engine( $db );
        $have = self::existingTables( $db );
        $missing = array_values( array_diff( self::TABLES, $have ) );
        $lines = array( 'database: ' . $engine, 'missing: ' . ( $missing ? implode( ', ', $missing ) : 'none' ) );
        $ok = true;
        if ( $missing && in_array( $engine, array( 'postgresql', 'mysql', 'sqlite' ), true ) )
        {
            foreach ( preg_split( '/;\s*(\r?\n|$)/', (string)file_get_contents( rtrim( $sqlDir, '/' ) . '/' . $engine . '/schema.sql' ) ) as $s )
            {
                $s = trim( preg_replace( '/^\s*--.*$/m', '', $s ) );
                if ( $s === '' || !preg_match( '/(sevenx_paypal_(transaction|event|vault))/', $s, $m ) || !in_array( $m[1], $missing, true ) )
                    continue;
                $r = $db->query( $s );
                $lines[] = ( $r === false ? 'FAILED: ' : 'ran: ' ) . substr( preg_replace( '/\s+/', ' ', $s ), 0, 90 );
                $ok = $ok && $r !== false;
            }
        }
        else if ( $missing && $engine === 'mongodb' )
        {
            $h = self::handler( 'mongodb', array_intersect_key( self::definition(), array_flip( $missing ) ), $db );
            $ok = $h && method_exists( $h, 'insertSchema' ) && $h->insertSchema( array( 'schema' => true, 'data' => false ) );
            $lines[] = 'mongodb: ' . ( $ok ? 'collections created' : 'the schema handler could not create them' );
        }
        else if ( $missing )
        {
            $st = self::sqlStatements( $engine, $missing );
            if ( !$st )
            {
                $ok = false;
                $lines[] = "no schema handler for $engine";
            }
            foreach ( $st as $s )
            {
                $r = $db->query( $s );
                $lines[] = ( $r === false ? 'FAILED: ' : 'ran: ' ) . substr( preg_replace( '/\s+/', ' ', $s ), 0, 90 );
                $ok = $ok && $r !== false;
            }
        }
        $after = self::existingTables( $db );
        foreach ( self::TABLES as $t )
        {
            $lines[] = $t . ': ' . ( in_array( $t, $after, true ) ? ( in_array( $t, $have, true ) ? 'there already' : 'created' ) : 'MISSING' );
            $ok = $ok && in_array( $t, $after, true );
        }
        return array( 'ok' => $ok, 'lines' => $lines );
    }
}
