<?php
/**
 * Content Control - role-match modes on the central access decision.
 *
 * can_view() gains a fourth argument: any (login is enough), match (must
 * hold a listed role - today's behaviour and the default), exclude (any
 * logged-in user EXCEPT the listed roles). who_allows() is the restriction
 * shape wrapper: {status, role_match, roles}.
 */

require_once __DIR__ . '/bootstrap.php';

// --- the slice of WordPress the access class touches ---
$GLOBALS['dpt_stub_user'] = (object) array( 'ID' => 5, 'roles' => array( 'editor' ) );
function wp_get_current_user() { return $GLOBALS['dpt_stub_user']; }
function user_can( $user, $cap ) { return in_array( 'administrator', (array) $user->roles, true ); }
function wpautop( $s ) { return $s; }

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-access.php';

$editor     = (object) array( 'ID' => 5, 'roles' => array( 'editor' ) );
$subscriber = (object) array( 'ID' => 6, 'roles' => array( 'subscriber' ) );
$anon       = (object) array( 'ID' => 0, 'roles' => array() );

/* role_match = match (default, unchanged behaviour) */
dpt_test_ok( DPT_CC_Access::can_view( 'roles', array( 'editor' ), $editor ), 'match: editor sees editor-gated' );
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array( 'editor' ), $subscriber ), 'match: subscriber does not' );
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array(), $subscriber ), 'match: empty role list still refuses (old semantics kept)' );

/* role_match = exclude - everyone logged in EXCEPT the listed roles */
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array( 'editor' ), $editor, 'exclude' ), 'exclude: listed role is refused' );
dpt_test_ok( DPT_CC_Access::can_view( 'roles', array( 'editor' ), $subscriber, 'exclude' ), 'exclude: unlisted role passes' );
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array( 'editor' ), $anon, 'exclude' ), 'exclude: still requires login' );

/* role_match = any - login is enough, roles ignored */
dpt_test_ok( DPT_CC_Access::can_view( 'roles', array( 'editor' ), $subscriber, 'any' ), 'any: any logged-in user passes' );
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array( 'editor' ), $anon, 'any' ), 'any: anonymous refused' );

/* garbage role_match falls back to match */
dpt_test_ok( ! DPT_CC_Access::can_view( 'roles', array( 'editor' ), $subscriber, 'sideways' ), 'unknown match mode behaves like match' );

/* admin bypass still wins in every mode */
$admin = (object) array( 'ID' => 1, 'roles' => array( 'administrator' ) );
dpt_test_ok( DPT_CC_Access::can_view( 'roles', array( 'administrator' ), $admin, 'exclude' ), 'admin bypasses even an exclude naming their role' );

/* who_allows() wrapper */
dpt_test_ok( DPT_CC_Access::who_allows( array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ), $subscriber ), 'who_allows logged_in/any' );
dpt_test_ok( DPT_CC_Access::who_allows( array( 'status' => 'logged_out', 'role_match' => 'any', 'roles' => array() ), $anon ), 'who_allows logged_out for anon' );
dpt_test_ok( ! DPT_CC_Access::who_allows( array( 'status' => 'logged_out', 'role_match' => 'any', 'roles' => array() ), $editor ), 'who_allows logged_out refuses logged-in' );
dpt_test_ok( ! DPT_CC_Access::who_allows( array( 'status' => 'logged_in', 'role_match' => 'exclude', 'roles' => array( 'editor' ) ), $editor ), 'who_allows exclude refuses listed role' );
dpt_test_ok( DPT_CC_Access::who_allows( array( 'status' => 'logged_in', 'role_match' => 'match', 'roles' => array() ), $subscriber ), 'who_allows: empty roles means any role' );
dpt_test_ok( ! DPT_CC_Access::who_allows( array(), $editor ), 'who_allows empty who fails closed' );
dpt_test_ok( ! DPT_CC_Access::who_allows( array( 'status' => 'sideways' ), $editor ), 'who_allows bad status fails closed' );

exit( dpt_test_summary() );
