<?php
// Body scan of WooCommerce db-update routines. Env: PKG=<unzipped package dir>, ROUTINES=a,b,c. Exit 0 ALLOW / 1 BLOCK / 2 error.
$pkg = rtrim( getenv( 'PKG' ), '/' ); $routines = array_filter( explode( ',', (string) getenv( 'ROUTINES' ) ) );
$src = @file_get_contents( "{$pkg}/includes/wc-update-functions.php" ); if ( ! $src ) { echo "SCAN_ERROR: wc-update-functions.php not found\n"; exit( 2 ); }
$block = [ 'dbDelta(', 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE', 'INSERT INTO', 'REPLACE INTO', 'DELETE FROM', '$wpdb->insert(', '$wpdb->update(', '$wpdb->replace(', '$wpdb->query(', '$wpdb->delete(', '"UPDATE ', "'UPDATE ", 'wc_get_orders(', 'wc_get_products(', 'OrdersTableDataStore', 'DataSynchronizer', 'as_schedule_', 'as_enqueue_', '->schedule_single(', '->schedule_recurring(', 'WC()->queue()', 'wp_update_post(', 'wp_insert_post(', 'update_user_meta(', 'wc_update_order(', 'wp_schedule_event(' ];
$post_write = [ 'wp_delete_post(', 'wp_trash_post(', 'update_post_meta(', 'delete_post_meta(', 'add_post_meta(' ];
$internal_post_guard = [ 'EMAIL_POST_TYPE', "'woo_email'" ];
function body_of( $src, $fn ) { if ( ! preg_match( '/function\s+' . preg_quote( $fn, '/' ) . '\s*\([^)]*\)\s*(?::\s*[\w|?\\\\]+\s*)?\{/', $src, $m, PREG_OFFSET_CAPTURE ) ) { return null; } $s = $m[0][1] + strlen( $m[0][0] ); $d = 1; $i = $s; $n = strlen( $src ); while ( $i < $n && $d > 0 ) { $c = $src[ $i ]; if ( '{' === $c ) { $d++; } elseif ( '}' === $c ) { $d--; } $i++; } return substr( $src, $s, $i - $s - 1 ); }
function strip_comments( $s ) { return preg_replace( [ '#/\*.*?\*/#s', '#^\s*//.*$#m' ], '', $s ); }
function resolve_class( $pkg, $src, $short ) { // via use-statement in wc-update-functions.php, PSR-4 Automattic\WooCommerce\ -> src/
    if ( preg_match( '/^use\s+([\w\\\\]+\\\\' . preg_quote( $short, '/' ) . ')\s*;/m', $src, $m ) ) { $fqcn = $m[1]; } elseif ( preg_match( '/\\\\((?:Automattic\\\\WooCommerce\\\\)[\w\\\\]*' . preg_quote( $short, '/' ) . ')::class/', $src, $m ) ) { $fqcn = $m[1]; } else { return [ null, null ]; }
    $rel = str_replace( 'Automattic\\WooCommerce\\', '', $fqcn ); $f = "{$pkg}/src/" . str_replace( '\\', '/', $rel ) . '.php'; return [ $fqcn, file_exists( $f ) ? $f : null ]; }
$verdict = 'ALLOW'; $report = [];
foreach ( $routines as $fn ) {
    $body = body_of( $src, $fn ); if ( null === $body ) { $report[] = "{$fn}: NOT FOUND -> BLOCK"; $verdict = 'BLOCK'; continue; }
    $scope = strip_comments( $body ); $files = [ 'routine body' ];
    // one-level delegation: Class::method( or ( new Class( / new Class(
    preg_match_all( '/\b([A-Z][A-Za-z0-9_]+)(?:::[a-z_]+\(|\s*\(\s*\)\s*\)\s*->|\s*\()/', $scope, $cm );
    foreach ( array_unique( $cm[1] ?? [] ) as $short ) { if ( in_array( $short, [ 'WP_Post', 'WC_Email' ], true ) ) { continue; } [ $fqcn, $file ] = resolve_class( $pkg, $src, $short ); if ( $fqcn && ! $file ) { $report[] = "{$fn}: delegates to {$fqcn} but its file is not in the package -> BLOCK"; $verdict = 'BLOCK'; continue; } if ( $file ) { $scope .= "\n" . strip_comments( file_get_contents( $file ) ); $files[] = str_replace( "{$pkg}/", '', $file ); } }
    $hits = []; foreach ( $block as $t ) { if ( false !== stripos( $scope, $t ) ) { $hits[] = $t; } }
    $pw = []; foreach ( $post_write as $t ) { if ( false !== strpos( $scope, $t ) ) { $pw[] = $t; } }
    $guarded = false; foreach ( $internal_post_guard as $g ) { if ( false !== strpos( $scope, $g ) ) { $guarded = true; } }
    $line = "{$fn}: scanned " . implode( ' + ', $files );
    if ( $hits ) { $line .= " -> BLOCK (schema/data/scheduling: " . implode( ', ', array_unique( $hits ) ) . ")"; $verdict = 'BLOCK'; }
    elseif ( $pw && ! $guarded ) { $line .= " -> BLOCK (post writes " . implode( ', ', $pw ) . " without an internal post-type guard)"; $verdict = 'BLOCK'; }
    elseif ( $pw ) { $line .= " -> allow (post writes " . implode( ', ', $pw ) . " guarded by WooCommerce's own email-template post type)"; }
    else { $line .= " -> allow (transients/options/caches only)"; }
    $report[] = $line;
}
echo implode( "\n", $report ) . "\nVERDICT={$verdict}\n"; exit( 'ALLOW' === $verdict ? 0 : 1 );
