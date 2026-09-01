<?php
/**
 * Content Control - the restrictions form's conditions parser. Checkboxes
 * that browsers omit when unticked must not shift NOT flags onto the wrong
 * rule row (Codex round-1 P1): every field posts with an explicit row
 * index, and the parser reads values by that index.
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname( __DIR__ ) . '/modules/content-control/class-dpt-cc-restrictions-admin.php';

$_POST = array(
	'cond_op'     => 'or',
	'cond_rule'   => array( 0 => 'rule_a', 1 => 'rule_b', 2 => '' ), // row 2 = the blank spare
	'cond_not'    => array( 1 => '1' ), // only row 1 was ticked - rows 0 and 2 absent
	'cond_value'  => array( 0 => '', 1 => '7,8' ),
	'gcond_op'    => 'and',
	'gcond_rule'  => array( 0 => 'rule_c' ),
	'gcond_value' => array( 0 => 'tpl.php' ),
);

$c = DPT_CC_Restrictions_Admin::conditions_from_post();

dpt_test_eq( $c['operator'], 'or', 'root operator read' );
dpt_test_eq( count( $c['items'] ), 3, 'two rules plus the group; the blank spare row dropped' );
dpt_test_eq( $c['items'][0]['name'], 'rule_a', 'first rule kept' );
dpt_test_eq( $c['items'][0]['not'], false, 'unticked NOT stays off for row 0' );
dpt_test_eq( $c['items'][1]['name'], 'rule_b', 'second rule kept' );
dpt_test_eq( $c['items'][1]['not'], true, 'NOT lands on the row whose box was ticked' );
dpt_test_eq( $c['items'][1]['options']['ids'], '7,8', 'value follows its own row' );
dpt_test_eq( $c['items'][2]['type'], 'group', 'group appended last' );
dpt_test_eq( $c['items'][2]['operator'], 'and', 'group operator read' );
dpt_test_eq( $c['items'][2]['items'][0]['name'], 'rule_c', 'group rule kept' );
dpt_test_eq( $c['items'][2]['items'][0]['options']['template'], 'tpl.php', 'group value carried' );
dpt_test_eq( $c['items'][2]['items'][0]['not'], false, 'absent group NOT array means no negation' );

/* no group rows -> no group item */
unset( $_POST['gcond_rule'], $_POST['gcond_value'] );
$c2 = DPT_CC_Restrictions_Admin::conditions_from_post();
dpt_test_eq( count( $c2['items'] ), 2, 'without group rows, only the root rules remain' );

exit( dpt_test_summary() );
