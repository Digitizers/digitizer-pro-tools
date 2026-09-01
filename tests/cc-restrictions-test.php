<?php
/**
 * Content Control - global restriction rows: sanitization, storage order,
 * enabled filtering and first-match resolution with memoization.
 *
 * The rule engine is substituted with a scripted stand-in: the store's only
 * contract with it is DPT_CC_Rules::check( $conditions, $context ).
 */

require_once __DIR__ . '/bootstrap.php';

// Rule engine substitute - answers are scripted per test step.
class DPT_CC_Rules {
	public static $answers = array();
	public static function check( $conditions, $context ) {
		$id = isset( $context['probe'] ) ? $context['probe'] : '';
		return ! empty( self::$answers[ $id ] );
	}
}

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-restrictions.php';

function dpt_test_ids( $rows ) {
	$o = array();
	foreach ( $rows as $r ) {
		$o[] = $r['id'];
	}
	return $o;
}

/* ---- sanitize_row: garbage in, complete inert row out ---- */

$row = DPT_CC_Restrictions::sanitize_row( array( 'title' => ' A ', 'protection' => array( 'method' => 'evil' ), 'archive_handling' => 'nope' ) );
dpt_test_eq( $row['title'], 'A', 'title trimmed' );
dpt_test_eq( $row['protection']['method'], 'redirect', 'bad method falls to redirect' );
dpt_test_eq( $row['archive_handling'], 'filter', 'bad archive handling falls to filter' );
dpt_test_ok( '' !== $row['id'], 'id generated' );
dpt_test_eq( $row['conditions'], array( 'operator' => 'and', 'items' => array() ), 'conditions default empty-and' );
dpt_test_eq( $row['who'], array( 'status' => 'logged_in', 'role_match' => 'any', 'roles' => array() ), 'who defaults' );
dpt_test_eq( $row['enabled'], false, 'a row saved without the enabled flag is off' );

/* conditions sanitization: rules kept, junk dropped, one group level */
$c = DPT_CC_Restrictions::sanitize_conditions( array(
	'operator' => 'or',
	'items'    => array(
		array( 'type' => 'rule', 'name' => 'Content_Is_Page!', 'not' => 1, 'options' => array( 'ids' => ' 1, 2 ' ) ),
		'junk',
		array( 'type' => 'group', 'operator' => 'and', 'items' => array(
			array( 'type' => 'rule', 'name' => 'entire_site' ),
			array( 'no' => 'name' ),
		) ),
		array( 'type' => 'group', 'items' => array() ), // empty group dropped
	),
) );
dpt_test_eq( $c['operator'], 'or', 'operator kept' );
dpt_test_eq( count( $c['items'] ), 2, 'junk and empty group dropped' );
dpt_test_eq( $c['items'][0]['name'], 'content_is_page', 'rule name sanitized' );
dpt_test_eq( $c['items'][0]['not'], true, 'not flag kept' );
dpt_test_eq( $c['items'][1]['type'], 'group', 'group survives' );
dpt_test_eq( count( $c['items'][1]['items'] ), 1, 'nameless rule dropped inside group' );

/* ---- save_all + all round trip, order preserved ---- */

$GLOBALS['dpt_stub_options'] = array();
DPT_CC_Restrictions::save_all( array(
	array( 'id' => 'r_one', 'title' => 'One', 'enabled' => true ),
	array( 'id' => 'r_two', 'title' => 'Two' ),
	array( 'id' => 'r_one', 'title' => 'Dup' ), // duplicate id dropped
) );
$all = DPT_CC_Restrictions::all();
dpt_test_eq( count( $all ), 2, 'duplicate id dropped on save' );
dpt_test_eq( dpt_test_ids( $all ), array( 'r_one', 'r_two' ), 'order preserved' );
dpt_test_eq( count( DPT_CC_Restrictions::enabled() ), 1, 'enabled() filters disabled rows' );
dpt_test_eq( DPT_CC_Restrictions::get( 'r_two' )['title'], 'Two', 'get() finds by id' );
dpt_test_eq( DPT_CC_Restrictions::get( 'r_none' ), null, 'get() misses cleanly' );

/* ---- match(): first enabled match wins, memoized per context ---- */

$conds = array( 'operator' => 'and', 'items' => array( array( 'type' => 'rule', 'name' => 'x', 'not' => false, 'options' => array() ) ) );
DPT_CC_Restrictions::save_all( array(
	array( 'id' => 'r_off', 'enabled' => false, 'conditions' => $conds ),
	array( 'id' => 'r_a', 'enabled' => true, 'conditions' => $conds ),
	array( 'id' => 'r_b', 'enabled' => true, 'conditions' => $conds ),
) );
DPT_CC_Rules::$answers = array( 'p1' => true );
$m = DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 1, 'probe' => 'p1' ) );
dpt_test_eq( $m ? $m['id'] : null, 'r_a', 'first enabled match wins (disabled r_off skipped)' );

DPT_CC_Rules::$answers = array(); // the engine now says no...
$m2 = DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 1, 'probe' => 'p1' ) );
dpt_test_eq( $m2 ? $m2['id'] : null, 'r_a', '...but the memoized answer is returned for the same context' );

DPT_CC_Restrictions::flush_cache();
dpt_test_eq( DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 1, 'probe' => 'p1' ) ), null, 'after flush, no match' );

/* different contexts get different cache slots */
DPT_CC_Rules::$answers = array( 'p9' => true );
dpt_test_ok( null !== DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 9, 'probe' => 'p9' ) ), 'other post id resolves independently' );

/* ---- rows with empty conditions never match ---- */

DPT_CC_Restrictions::save_all( array( array( 'id' => 'r_e', 'enabled' => true ) ) );
DPT_CC_Rules::$answers = array( 'p2' => true );
dpt_test_eq( DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 2, 'probe' => 'p2' ) ), null, 'empty conditions never match' );

/* save_all flushes the memo */
DPT_CC_Rules::$answers = array( 'p3' => true );
DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 3, 'probe' => 'p3' ) ); // caches null (r_e has no rules)
DPT_CC_Restrictions::save_all( array( array( 'id' => 'r_f', 'enabled' => true, 'conditions' => $conds ) ) );
$m3 = DPT_CC_Restrictions::match( array( 'type' => 'post', 'post_id' => 3, 'probe' => 'p3' ) );
dpt_test_eq( $m3 ? $m3['id'] : null, 'r_f', 'save_all flushes the match memo' );

exit( dpt_test_summary() );
