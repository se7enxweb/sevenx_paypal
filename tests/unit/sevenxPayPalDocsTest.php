<?php
use PHPUnit\Framework\TestCase;

/** The documentation matches the code: every setting is documented, every link resolves, the doc list is complete. */
class sevenxPayPalDocsTest extends TestCase
{
    protected static function root()
    {
        return dirname( __DIR__, 2 );
    }

    public function testEverySettingIsDocumented()
    {
        $ini = file_get_contents( self::root() . '/settings/paypal.ini.append.php' );
        $doc = file_get_contents( self::root() . '/doc/configuration.md' );
        preg_match_all( '/^([A-Za-z]+)(\[\])?=/m', $ini, $m );
        $this->assertNotEmpty( $m[1] );
        foreach ( array_unique( $m[1] ) as $name )
            $this->assertStringContainsString( '`' . $name, $doc, "doc/configuration.md documents $name" );
    }

    public function testEveryLinkResolves()
    {
        $files = array_merge( glob( self::root() . '/doc/*.md' ), array( self::root() . '/README.md', self::root() . '/CHANGELOG.md' ) );
        foreach ( $files as $f )
        {
            preg_match_all( '/\]\(([^)\s]+)\)/', file_get_contents( $f ), $m );
            foreach ( $m[1] as $link )
            {
                if ( preg_match( '#^(https?:|mailto:|\#)#', $link ) )
                    continue;
                $path = dirname( $f ) . '/' . preg_replace( '/#.*$/', '', $link );
                $this->assertFileExists( $path, basename( $f ) . ' links to ' . $link );
            }
        }
    }

    public function testTheIndexListsEveryPage()
    {
        $index = file_get_contents( self::root() . '/doc/README.md' );
        foreach ( glob( self::root() . '/doc/*.md' ) as $f )
            if ( basename( $f ) !== 'README.md' )
                $this->assertStringContainsString( '(' . basename( $f ) . ')', $index, 'doc/README.md links ' . basename( $f ) );
    }

    public function testNoOldProductName()
    {
        foreach ( array_merge( glob( self::root() . '/doc/*.md' ), array( self::root() . '/README.md' ) ) as $f )
            $this->assertDoesNotMatchRegularExpression( '/\bez\s+publish\b/i', file_get_contents( $f ), basename( $f ) . ' says Exponential (a package type like ezpublish-legacy-extension is a real identifier)' );
    }
}
