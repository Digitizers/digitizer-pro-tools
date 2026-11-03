<?php
/**
 * Onboarding's automatic-updates box starts unticked. Ticked by default, a
 * run that nobody read closely enrolled Digitizer Pro Tools in WordPress's
 * unattended updates, and on a site whose plugin folder had mixed file
 * ownership the upgrader then deleted the plugin's main file (3.11). Turning
 * unattended updates on is an operator's choice, made on purpose.
 */
require_once __DIR__ . '/bootstrap.php';

$src = (string) file_get_contents( dirname( __DIR__ ) . '/modules/onboarding/class-dpt-onb-admin.php' );
dpt_test_ok( 1 === preg_match( '/<input type="checkbox" id="dpt-onb-auto-update"\s*\/>/', $src ), 'the automatic-updates box is rendered unticked' );
dpt_test_ok( 1 === preg_match( '/<input type="checkbox" id="dpt-onb-cleanup" checked \/>/', $src ), 'the theme clean-up box keeps its default' );

$js = (string) file_get_contents( dirname( __DIR__ ) . '/modules/onboarding/assets/js/wizard.js' );
dpt_test_ok( false !== strpos( $js, "return box ? box.checked : false;" ), 'and the wizard sends the request only when it is ticked' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
