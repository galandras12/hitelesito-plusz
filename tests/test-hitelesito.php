<?php
/**
 * Test script for Hitelesítő+ plugin logic.
 */

define( 'ABSPATH', __DIR__ . '/mock/' );

class MockWPDB {
	public $prefix = 'wp_';
	public $tables = array();
	public $last_id = 0;

	public function get_charset_collate() { return ''; }

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( ! isset( $this->tables[ $table ] ) ) return 0;
		$count = 0;
		foreach ( $this->tables[ $table ] as &$row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( ! isset( $row->$k ) || $row->$k != $v ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				foreach ( $data as $k => $v ) {
					$row->$k = $v;
				}
				$count++;
			}
		}
		return $count;
	}

	public function insert( $table, $data, $format = null ) {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array();
		}
		$this->last_id++;
		$data['id'] = $this->last_id;
		$obj = (object) $data;
		$this->tables[ $table ][] = $obj;
		return 1;
	}

	public function prepare( $query, ...$args ) {
		// If first arg is array (from vsprintf style)
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			if ( is_int( $arg ) || is_float( $arg ) ) {
				$query = preg_replace( '/%[d|f]/', $arg, $query, 1 );
			} else {
				$escaped = addslashes( (string) $arg );
				$query = preg_replace( '/%s/', "'$escaped'", $query, 1 );
			}
		}
		return $query;
	}

	public function get_results( $query ) {
		// Mock parsing for email codes query
		if ( strpos( $query, 'h2f_email_codes' ) !== false ) {
			preg_match( "/user_id = (\d+)/", $query, $m_user );
			preg_match( "/used = (\d+)/", $query, $m_used );
			preg_match( "/expires_at >= '([^']+)'/", $query, $m_exp );

			$user_id = isset( $m_user[1] ) ? (int) $m_user[1] : 0;
			$used = isset( $m_used[1] ) ? (int) $m_used[1] : 0;
			$expires_at = isset( $m_exp[1] ) ? $m_exp[1] : '';

			$table = $this->prefix . 'h2f_email_codes';
			$rows = isset( $this->tables[ $table ] ) ? $this->tables[ $table ] : array();

			$res = array();
			foreach ( $rows as $r ) {
				if ( $r->user_id == $user_id && $r->used == $used && $r->expires_at >= $expires_at ) {
					$res[] = clone $r;
				}
			}
			usort( $res, function( $a, $b ) { return $b->id - $a->id; } );
			return $res;
		}
		return array();
	}

	public function get_row( $query ) {
		$res = $this->get_results( $query );
		return ! empty( $res ) ? $res[0] : null;
	}

	public function query( $q ) { return true; }
	public function delete( $table, $where, $format = null ) {
		if ( ! isset( $this->tables[ $table ] ) ) return 0;
		$count = 0;
		foreach ( $this->tables[ $table ] as $idx => $row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( ! isset( $row->$k ) || $row->$k != $v ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				unset( $this->tables[ $table ][ $idx ] );
				$count++;
			}
		}
		return $count;
	}
}

global $wpdb;
$wpdb = new MockWPDB();

// Mock WordPress functions
$GLOBALS['transients'] = array();
$GLOBALS['wp_login_actions'] = array();
$GLOBALS['auth_cookie_set'] = false;

function is_ssl() { return false; }
function get_option( $key, $default = false ) { return $default; }
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
function set_transient( $key, $val, $exp ) { $GLOBALS['transients'][$key] = array('val' => $val, 'exp' => time() + $exp); }
function get_transient( $key ) {
	if ( isset( $GLOBALS['transients'][$key] ) ) {
		if ( $GLOBALS['transients'][$key]['exp'] >= time() ) {
			return $GLOBALS['transients'][$key]['val'];
		}
	}
	return false;
}
function delete_transient( $key ) { unset( $GLOBALS['transients'][$key] ); }
function wp_hash_password( $p ) { return 'hash_' . $p; }
function wp_check_password( $p, $h ) { return $h === 'hash_' . $p; }
function __( $str, $dom = '' ) { return $str; }
function esc_html__( $str, $dom = '' ) { return $str; }
function get_bloginfo( $key ) { return 'TestSite'; }
function add_filter() {}
function remove_filter() {}
function wp_mail() { return true; }

class WP_User {
	public $ID = 1;
	public $user_email = 'test@example.com';
	public $display_name = 'Test User';
	public $user_login = 'testuser';
	public $roles = array( 'administrator' );
}

function get_userdata( $id ) {
	$u = new WP_User();
	$u->ID = $id;
	return $u;
}

class WP_Error {
	protected $msg;
	public function __construct( $code, $msg ) { $this->msg = $msg; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function wp_set_auth_cookie( $user_id, $remember = false ) {
	$GLOBALS['auth_cookie_set'] = true;
}
function wp_set_current_user( $user_id ) {}
function wp_clear_auth_cookie() {
	$GLOBALS['auth_cookie_set'] = false;
}

$GLOBALS['hooks'] = array();
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['hooks'][$tag][$priority][] = $callback;
}
function remove_action( $tag, $callback, $priority = 10 ) {
	if ( isset( $GLOBALS['hooks'][$tag][$priority] ) ) {
		foreach ( $GLOBALS['hooks'][$tag][$priority] as $k => $cb ) {
			if ( $cb === $callback ) {
				unset( $GLOBALS['hooks'][$tag][$priority][$k] );
			}
		}
	}
}
function do_action( $tag, ...$args ) {
	if ( ! empty( $GLOBALS['hooks'][$tag] ) ) {
		ksort( $GLOBALS['hooks'][$tag] );
		foreach ( $GLOBALS['hooks'][$tag] as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				call_user_func_array( $cb, $args );
			}
		}
	}
}

require_once __DIR__ . '/../includes/class-h2f-db.php';
require_once __DIR__ . '/../includes/class-h2f-settings.php';
require_once __DIR__ . '/../includes/class-h2f-email-2fa.php';
require_once __DIR__ . '/../includes/class-h2f-login-flow.php';

// --- Test 1: Email Code Sending & Multi-code verification ---
echo "Test 1: Email 2FA Code Generation and Verification\n";

$user = new WP_User();
$res1 = H2F_Email_2FA::send_code( $user );
assert( $res1 === true, 'First code sending failed' );

// Get inserted code from mock db
$table = $wpdb->prefix . 'h2f_email_codes';
assert( count( $wpdb->tables[$table] ) === 1, 'Expected 1 email code in DB' );

$code1_hash = $wpdb->tables[$table][0]->code_hash;
$code1 = str_replace( 'hash_', '', $code1_hash );

// Throttle test
$res_throttle = H2F_Email_2FA::send_code( $user );
assert( is_wp_error( $res_throttle ), 'Expected throttle WP_Error on rapid resend' );

// Clear throttle transient to simulate resend after wait
delete_transient( 'h2f_email_code_throttle_1' );

$res2 = H2F_Email_2FA::send_code( $user );
assert( $res2 === true, 'Second code sending failed' );
assert( count( $wpdb->tables[$table] ) === 2, 'Expected 2 email codes in DB' );

$code2_hash = $wpdb->tables[$table][1]->code_hash;
$code2 = str_replace( 'hash_', '', $code2_hash );

// Verify code1 (from first email) STILL WORKS
$reason = null;
$verify_res1 = H2F_Email_2FA::verify_and_consume( 1, $code1, $reason );
assert( $verify_res1 === true, "Verification of code1 failed with reason: $reason" );

// Verify code1 CANNOT be reused
$reason_reuse = null;
$verify_res_reuse = H2F_Email_2FA::verify_and_consume( 1, $code1, $reason_reuse );
assert( $verify_res_reuse === false, 'Reuse of code1 should fail' );
assert( $reason_reuse === 'code_mismatch' || $reason_reuse === 'no_active_code', 'Expected mismatch or no_active_code on reuse' );

// Verify code2 (from second email) ALSO WORKS
$reason2 = null;
$verify_res2 = H2F_Email_2FA::verify_and_consume( 1, $code2, $reason2 );
assert( $verify_res2 === true, "Verification of code2 failed with reason: $reason2" );

echo "-> Email 2FA Tests Passed!\n\n";

// --- Test 2: Login Flow Finalization & Recursion Guard ---
echo "Test 2: Login Flow Finalization and Intercept Guard\n";

H2F_Login_Flow::init();

$session = array(
	'user_id' => 1,
	'token' => 'testtoken123',
	'remember' => false,
);

set_transient( 'h2f_pending_testtoken123', $session, 600 );

$fin_res = H2F_Login_Flow::finalize_login( $session );
assert( $fin_res === true, 'finalize_login failed' );
assert( $GLOBALS['auth_cookie_set'] === true, 'Auth cookie should remain TRUE after finalize_login' );

echo "-> Login Flow Finalization Test Passed!\n\n";

echo "ALL TESTS PASSED SUCCESSFULLY!\n";
