<?php
$installed = getenv('INSTALLED'); $target = getenv('TARGET');
function keys( $f ) {
    $s = file_get_contents( $f );
    if ( ! preg_match( '/\$db_updates\s*=\s*(?:array\(|\[)(.*?)^\s*(?:\)|\]);/ms', $s, $m ) ) { return null; }
    preg_match_all( "/^\s*'([0-9.]+)'\s*=>/m", $m[1], $k );
    return $k[1];
}
$a = keys( 'install-installed.php' ); $b = keys( "install-{$target}.php" );
if ( null === $a || null === $b ) { echo "GATE_ERROR: db_updates array not found\n"; exit( 2 ); }
echo "installed_file: keys=" . count( $a ) . " last=" . end( $a ) . "\n";
echo "target_file:    keys=" . count( $b ) . " last=" . end( $b ) . "\n";
$applies = array_values( array_filter( $b, fn( $v ) => version_compare( $v, $installed, '>' ) && version_compare( $v, $target, '<=' ) ) );
echo "DB_UPDATES_BETWEEN_{$installed}_AND_{$target}=" . ( $applies ? implode( ',', $applies ) : 'NONE' ) . "\n";
if ( $applies ) { $s = file_get_contents( "install-{$target}.php" ); foreach ( $applies as $v ) { if ( preg_match( "/'" . preg_quote( $v, '/' ) . "'\s*=>\s*(?:array\(|\[)(.*?)(?:\)|\])/ms", $s, $m ) ) { preg_match_all( "/'([a-z0-9_]+)'/", $m[1], $c ); echo "  {$v}: " . implode( ', ', $c[1] ) . "\n"; } } }
exit( $applies ? 1 : 0 );
