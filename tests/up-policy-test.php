<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/modules/update-policy/class-dpt-up-version.php';
require_once dirname( __DIR__ ) . '/modules/update-policy/class-dpt-up-offers.php';
require_once dirname( __DIR__ ) . '/modules/update-policy/class-dpt-up-settings.php';
require_once dirname( __DIR__ ) . '/modules/update-policy/class-dpt-up-policy.php';
require_once dirname( __DIR__ ) . '/modules/update-policy/class-dpt-up-health.php';
require_once dirname( __DIR__ ) . '/modules/update-policy/class-dpt-up-admin.php';

/* ---- what counts as a major ---- */

dpt_test_eq( DPT_UP_Version::branch( '7.0.4' ), '7.0', 'a branch is the first two segments' );
dpt_test_eq( DPT_UP_Version::branch( '7.1' ), '7.1', 'with or without a third' );
dpt_test_eq( DPT_UP_Version::branch( 'nonsense' ), '', 'and unreadable input has none' );

dpt_test_ok( DPT_UP_Version::is_major( '7.0.4', '7.1' ), '7.0.4 to 7.1 crosses a branch' );
dpt_test_ok( DPT_UP_Version::is_major( '6.9', '7.0' ), 'and so does 6.9 to 7.0' );
dpt_test_ok( ! DPT_UP_Version::is_major( '7.0.4', '7.0.5' ), 'a fix on the same branch does not' );
dpt_test_ok( ! DPT_UP_Version::is_major( '7.1', '7.1' ), 'nor does standing still' );
dpt_test_ok( ! DPT_UP_Version::is_major( '7.1', '7.0.5' ), 'nor does going backwards' );

// Anything unreadable resolves towards not holding: a site stuck on an old
// WordPress because a version string could not be parsed would be a fault
// nobody could see.
dpt_test_ok( ! DPT_UP_Version::is_major( '', '7.1' ), 'an unreadable installed version holds nothing' );
dpt_test_ok( ! DPT_UP_Version::is_major( '7.0', '' ), 'nor does an unreadable offer' );

/* ---- the window ---- */

$day = 86400;
$t0  = 1000000;

dpt_test_ok( DPT_UP_Version::is_held( $t0, 30, $t0 ), 'a release seen today is held' );
dpt_test_ok( DPT_UP_Version::is_held( $t0, 30, $t0 + ( 29 * $day ) ), 'still held on the last day' );
dpt_test_ok( ! DPT_UP_Version::is_held( $t0, 30, $t0 + ( 30 * $day ) ), 'and free once the window has passed' );
dpt_test_eq( DPT_UP_Version::held_until( $t0, 30 ), $t0 + ( 30 * $day ), 'the end of the window is arithmetic, not a guess' );

// The setting turning the feature off and the setting being nonsense are the
// same code path on purpose.
dpt_test_ok( ! DPT_UP_Version::is_held( $t0, 0, $t0 ), 'zero days holds nothing' );
dpt_test_ok( ! DPT_UP_Version::is_held( $t0, -5, $t0 ), 'and neither does a negative window' );

// Never seen means the site has not recorded it yet, which is the one case
// where waiting is safer than installing.
dpt_test_ok( DPT_UP_Version::is_held( 0, 30, $t0 ), 'an unseen release is held until the next check records it' );

/* ---- filtering the offers ---- */

$updates = array(
	(object) array( 'current' => '7.0.5', 'response' => 'upgrade' ),
	(object) array( 'current' => '7.1', 'response' => 'upgrade' ),
);

$kept = DPT_UP_Offers::filter( $updates, '7.0.4', array( '7.1' => $t0 ), array(), 30, $t0 + $day );
dpt_test_eq( count( $kept ), 1, 'the held major is removed' );
dpt_test_eq( DPT_UP_Offers::version_of( array_shift( $kept ) ), '7.0.5', 'and the maintenance release in the same list survives' );

// This is the whole point of the module: security and maintenance releases are
// never touched, because they are what keeps a site alive.
$only_minor = DPT_UP_Offers::filter(
	array( (object) array( 'current' => '7.0.5' ) ),
	'7.0.4',
	array(),
	array(),
	30,
	$t0
);
dpt_test_eq( count( $only_minor ), 1, 'a site offered only a maintenance release is left entirely alone' );

$after = DPT_UP_Offers::filter( $updates, '7.0.4', array( '7.1' => $t0 ), array(), 30, $t0 + ( 31 * $day ) );
dpt_test_eq( count( $after ), 2, 'once the window passes the major comes back' );

$released = DPT_UP_Offers::filter( $updates, '7.0.4', array( '7.1' => $t0 ), array( '7.1' => 1 ), 30, $t0 + $day );
dpt_test_eq( count( $released ), 2, 'and a branch someone released by hand is never held again' );

// A release on a branch that is already released stays released - 7.1.1 is the
// same decision as 7.1, and asking again eight days later would be a hold the
// operator already answered.
$point_one = DPT_UP_Offers::filter(
	array( (object) array( 'current' => '7.1.1' ) ),
	'7.0.4',
	array( '7.1' => $t0 ),
	array( '7.1' => 1 ),
	30,
	$t0 + $day
);
dpt_test_eq( count( $point_one ), 1, 'a fix on a released branch is not held' );

// Input this cannot read is passed through rather than dropped.
$odd = DPT_UP_Offers::filter( array( (object) array( 'response' => 'upgrade' ), 'nonsense' ), '7.0.4', array(), array(), 30, $t0 );
dpt_test_eq( count( $odd ), 2, 'an offer with no version is left where it was' );
dpt_test_eq( DPT_UP_Offers::filter( 'not an array', '7.0.4', array(), array(), 30, $t0 ), 'not an array', 'and a transient of the wrong shape is returned untouched' );

// Keys are preserved: WordPress and other plugins index this array.
$keyed = DPT_UP_Offers::filter(
	array( 'a' => (object) array( 'current' => '7.0.5' ), 'b' => (object) array( 'current' => '7.1' ) ),
	'7.0.4',
	array( '7.1' => $t0 ),
	array(),
	30,
	$t0
);
dpt_test_ok( isset( $keyed['a'] ) && ! isset( $keyed['b'] ), 'the surviving offers keep their own keys' );

/* ---- stamping ---- */

$stamps = DPT_UP_Offers::stamp( array(), $updates, '7.0.4', $t0 );
dpt_test_eq( $stamps, array( '7.1' => $t0 ), 'a first sighting is recorded for the major, and only the major' );

$later = DPT_UP_Offers::stamp( $stamps, $updates, '7.0.4', $t0 + ( 5 * $day ) );
dpt_test_eq( $later, array( '7.1' => $t0 ), 'a later check does not move it - a window that restarts is not a window' );

$two = DPT_UP_Offers::stamp( array( '7.1' => $t0 ), array( (object) array( 'current' => '7.2' ) ), '7.0.4', $t0 + $day );
dpt_test_eq( $two, array( '7.1' => $t0, '7.2' => $t0 + $day ), 'and a second major gets a window of its own' );

/* ---- the read applies the hold and writes nothing ---- */

$GLOBALS['wp_version']       = '7.0.4';
$GLOBALS['dpt_stub_options'] = array(
	'dpt_update_policy' => array( 'hold_days' => 30, 'seen' => array( '7.1' => time() ), 'released' => array() ),
);
$before = $GLOBALS['dpt_stub_options'];

$transient = (object) array(
	'updates'      => array(
		(object) array( 'current' => '7.1' ),
		(object) array( 'current' => '7.0.5' ),
	),
	'last_checked' => 123,
);
$filtered = DPT_UP_Policy::apply_hold( $transient );

dpt_test_eq( count( $filtered->updates ), 1, 'the read removes the held major' );
dpt_test_eq( $GLOBALS['dpt_stub_options'], $before, 'and writes nothing at all' );
dpt_test_eq( count( $transient->updates ), 2, 'the object WordPress owns is left as it was' );
dpt_test_eq( $filtered->last_checked, 123, 'and everything else on it is carried across' );

// Nothing held: the same object comes back, not a copy, so a site with no
// policy in play is byte-for-byte what WordPress produced.
$GLOBALS['dpt_stub_options']['dpt_update_policy']['hold_days'] = 0;
dpt_test_ok( DPT_UP_Policy::apply_hold( $transient ) === $transient, 'with nothing held the transient is returned untouched' );

// A transient of the wrong shape is somebody else having got there first.
dpt_test_eq( DPT_UP_Policy::apply_hold( false ), false, 'and a transient that is not an object is left alone' );

/* ---- recording a sighting, from the stored value ---- */

$GLOBALS['dpt_stub_options']         = array();
$GLOBALS['dpt_stub_site_transients'] = array(
	'update_core' => (object) array(
		'updates' => array(
			(object) array( 'current' => '7.1' ),
			(object) array( 'current' => '7.0.5' ),
		),
	),
);

DPT_UP_Policy::record_sightings();
$saved = $GLOBALS['dpt_stub_options']['dpt_update_policy'];
dpt_test_ok( isset( $saved['seen']['7.1'] ), 'the check records the major it was offered' );
dpt_test_ok( ! isset( $saved['seen']['7.0'] ), 'and records nothing for the maintenance release' );

$first = $saved['seen']['7.1'];
DPT_UP_Policy::record_sightings();
dpt_test_eq(
	$GLOBALS['dpt_stub_options']['dpt_update_policy']['seen']['7.1'],
	$first,
	'a second check leaves the first sighting where it was'
);

// The stamp has to be read from the stored transient rather than through the
// module's own filter. Reading the filtered copy would hide the very major
// being held, so it would never be recorded - and never being recorded means
// held forever, the one outcome this module must not produce.
$GLOBALS['dpt_stub_options'] = array(
	'dpt_update_policy' => array( 'hold_days' => 30, 'seen' => array(), 'released' => array() ),
);
DPT_UP_Policy::record_sightings();
dpt_test_ok(
	isset( $GLOBALS['dpt_stub_options']['dpt_update_policy']['seen']['7.1'] ),
	'a major that is currently held is still recorded, so its window can end'
);

// A module switched on while a major is already offered has missed the check
// that would have stamped it. The hold is right either way, but the notice
// would have had no dates to show - the epoch, printed as 1970 - so the stamp
// is taken on the first admin page load as well as on a check.
$GLOBALS['dpt_stub_options'] = array(
	'dpt_update_policy' => array( 'hold_days' => 30, 'seen' => array(), 'released' => array() ),
);
DPT_UP_Policy::record_sightings();
$held_now = DPT_UP_Policy::held_majors();
dpt_test_ok( $held_now['7.1']['seen'] > 0, 'a release already offered when the module is switched on gets a real first-seen date' );
dpt_test_ok(
	$held_now['7.1']['until'] > $held_now['7.1']['seen'],
	'and a hold that ends after it began rather than in 1970'
);

/* ---- what the screen is told ---- */

$now = time();
$GLOBALS['dpt_stub_options'] = array(
	'dpt_update_policy' => array( 'hold_days' => 30, 'seen' => array( '7.1' => $now ), 'released' => array() ),
);
$held = DPT_UP_Policy::held_majors();
dpt_test_eq( count( $held ), 1, 'one major is held' );
dpt_test_eq( $held['7.1']['version'], '7.1', 'named by the version being offered' );
dpt_test_eq( $held['7.1']['until'], $now + ( 30 * 86400 ), 'with the date the hold ends' );

// The unattended path agrees with the visible one.
dpt_test_ok( ! DPT_UP_Policy::allow_major_auto( true ), 'unattended major updates are refused while one is held' );

DPT_UP_Policy::release( '7.1' );
dpt_test_eq( DPT_UP_Policy::held_majors(), array(), 'releasing a branch ends the hold' );
dpt_test_ok( DPT_UP_Policy::allow_major_auto( true ), 'and stops overriding the unattended setting' );
dpt_test_ok(
	true === DPT_UP_Policy::allow_major_auto( true ) && false === DPT_UP_Policy::allow_major_auto( false ),
	'which it only ever narrows, never widens'
);

// A release nobody is allowed to make is not made.
$GLOBALS['dpt_stub_options'] = array(
	'dpt_update_policy' => array( 'hold_days' => 30, 'seen' => array( '7.1' => $now ), 'released' => array() ),
);
$GLOBALS['dpt_stub_denied_caps'] = array( 'update_core' );
dpt_test_ok( ! DPT_UP_Policy::release( '7.1' ), 'releasing needs the capability to install a core update' );
dpt_test_eq( count( DPT_UP_Policy::held_majors() ), 1, 'and the hold survives the attempt' );
$GLOBALS['dpt_stub_denied_caps'] = array();

// On multisite the policy is network-wide, and so is the right to change it.
$GLOBALS['dpt_stub_multisite']   = true;
$GLOBALS['dpt_stub_denied_caps'] = array( 'manage_network_options' );
dpt_test_ok( ! DPT_UP_Settings::may_decide(), 'a site administrator on a network does not decide for the network' );
dpt_test_ok( ! DPT_UP_Policy::release( '7.1' ), 'and cannot release a hold' );
$GLOBALS['dpt_stub_denied_caps'] = array();
$GLOBALS['dpt_stub_multisite']   = false;

/* ---- on a network, the main site decides ---- */

// The module switch is per blog; a core update is not. A subsite that switched
// this on would hold nothing anyone else could see, while the network Updates
// screen and the update cron went on offering the major from the main site -
// a switch that looks like protection and is not. So it answers only where
// core updates are actually administered.
$GLOBALS['dpt_stub_filters']   = array();
$GLOBALS['dpt_stub_multisite'] = true;
$GLOBALS['dpt_stub_main_site'] = false;
DPT_UP_Policy::init();
dpt_test_ok( ! dpt_stub_has_filter( 'site_transient_update_core' ), 'a subsite on a network registers nothing' );

$GLOBALS['dpt_stub_main_site'] = true;
DPT_UP_Policy::init();
dpt_test_ok( dpt_stub_has_filter( 'site_transient_update_core' ), 'the main site does' );

$GLOBALS['dpt_stub_filters']   = array();
$GLOBALS['dpt_stub_multisite'] = false;
$GLOBALS['dpt_stub_main_site'] = false;
DPT_UP_Policy::init();
dpt_test_ok( dpt_stub_has_filter( 'site_transient_update_core' ), 'and a single site is never asked the question' );
$GLOBALS['dpt_stub_main_site'] = true;

/* ---- the unattended filter runs late enough to be heard ---- */

// Other update plugins hook this filter high on purpose so their answer is
// the last one - one shipping today uses 999. A plugin arguing for the update
// beats a plugin arguing against it by merely running later, so the number
// matters, and a test that only calls allow_major_auto() directly would pass
// no matter what number the module passed.
$GLOBALS['dpt_stub_filters'] = array();
DPT_UP_Policy::init();
dpt_test_eq(
	dpt_stub_filter_priority( 'allow_major_auto_core_updates', array( 'DPT_UP_Policy', 'allow_major_auto' ) ),
	9999,
	'the unattended filter is registered late'
);

$now = time();
$GLOBALS['dpt_stub_options'] = array(
	'dpt_update_policy' => array( 'hold_days' => 30, 'seen' => array( '7.1' => $now ), 'released' => array() ),
);

$say_yes = function () {
	return true;
};
add_filter( 'allow_major_auto_core_updates', $say_yes, 999 );
dpt_test_ok(
	! apply_filters( 'allow_major_auto_core_updates', false ),
	'a plugin insisting at 999 that the major may install is still overruled while one is held'
);

// And the ordering is what did it, not the assertion being unfalsifiable:
// with this module unhooked, the same competing filter gets its way.
remove_filter( 'allow_major_auto_core_updates', array( 'DPT_UP_Policy', 'allow_major_auto' ) );
dpt_test_ok(
	apply_filters( 'allow_major_auto_core_updates', false ),
	'the competing filter does decide when nothing is arguing with it'
);

/* ---- the Site Health entry ---- */

// A hold is a missing update, and a missing update is what someone sees when
// they go looking for a problem. Site Health is the third place they look.
$GLOBALS['dpt_stub_filters'] = array();
DPT_UP_Policy::init();
dpt_test_ok( dpt_stub_has_filter( 'site_status_tests' ), 'the main site publishes a Site Health entry' );

// A subsite decides nothing here, so it is told nothing either - the same
// early return that keeps it from filtering the transient.
$GLOBALS['dpt_stub_filters']   = array();
$GLOBALS['dpt_stub_multisite'] = true;
$GLOBALS['dpt_stub_main_site'] = false;
DPT_UP_Policy::init();
dpt_test_ok( ! dpt_stub_has_filter( 'site_status_tests' ), 'a subsite on a network publishes none' );
$GLOBALS['dpt_stub_multisite'] = false;
$GLOBALS['dpt_stub_main_site'] = true;

$registered = DPT_UP_Health::register( array() );
dpt_test_ok( isset( $registered['direct']['dpt_update_policy'] ), 'the entry is a direct test' );

$registered = DPT_UP_Health::register( array( 'direct' => array( 'something_else' => 1 ) ) );
dpt_test_ok( isset( $registered['direct']['something_else'] ), 'added beside the tests already registered' );
dpt_test_ok( isset( $registered['direct']['dpt_update_policy'] ), 'rather than in place of them' );

dpt_test_eq( DPT_UP_Health::register( 'not a registry' ), 'not a registry', 'and a registry it does not recognise is handed back untouched' );

// A hold in force. date_format is set for real: without it get_option()
// answers false, the formatted date is the empty string, and strpos() finds
// the empty string at 0 in anything - an assertion that cannot fail.
$GLOBALS['dpt_stub_options'] = array(
	'date_format'       => 'Y-m-d',
	'dpt_update_policy' => array( 'hold_days' => 30, 'seen' => array( '7.1' => $now ), 'released' => array() ),
);
$health   = DPT_UP_Health::run();
$ends_on  = gmdate( 'Y-m-d', $now + ( 30 * 86400 ) );
$began_on = gmdate( 'Y-m-d', $now );
dpt_test_eq( $health['status'], 'good', 'a hold in force is the policy working, not a fault to report' );
dpt_test_eq( $health['test'], 'dpt_update_policy', 'identified by the same id it was registered under' );
dpt_test_ok( false !== strpos( $health['description'], '7.1' ), 'and the entry names the release being held' );
dpt_test_ok( '' !== $ends_on && false !== strpos( $health['description'], $ends_on ), 'with the date the hold ends' );
dpt_test_ok( '' !== $began_on && false !== strpos( $health['description'], $began_on ), 'and the date the site first saw it' );

// Nothing held: still worth saying, because "the policy is on and holding
// nothing" is the reading that stops somebody hunting for a hold that was
// never there.
$GLOBALS['dpt_stub_options'] = array(
	'date_format'       => 'Y-m-d',
	'dpt_update_policy' => array( 'hold_days' => 30, 'seen' => array( '7.1' => $now ), 'released' => array( '7.1' => 1 ) ),
);
$health = DPT_UP_Health::run();
dpt_test_eq( $health['status'], 'good', 'holding nothing is not a fault either' );
dpt_test_ok( false !== strpos( $health['label'], 'No WordPress release' ), 'and says so plainly' );
dpt_test_ok( false !== strpos( $health['description'], 'never held' ), 'and repeats what is never held, which is the reassurance being asked for' );

// The length of the hold is not quoted, on purpose: saying it needs a plural
// form and this catalog has none. A one-day hold reads the same as any other.
$GLOBALS['dpt_stub_options'] = array(
	'date_format'       => 'Y-m-d',
	'dpt_update_policy' => array( 'hold_days' => 1, 'seen' => array( '7.1' => $now ), 'released' => array( '7.1' => 1 ) ),
);
dpt_test_ok( false === strpos( DPT_UP_Health::run()['description'], '1 day' ), 'with no day count that would need one' );

// Switched on and holding nothing back is a different thing from switched on
// and unable to hold anything. The second is a setting that contradicts the
// Modules screen, and is the one worth raising.
$GLOBALS['dpt_stub_options'] = array(
	'dpt_update_policy' => array( 'hold_days' => 0, 'seen' => array( '7.1' => $now ), 'released' => array() ),
);
$health = DPT_UP_Health::run();
dpt_test_eq( $health['status'], 'recommended', 'a zero-day hold is raised, because the module then does nothing it claims to' );
dpt_test_ok( false !== strpos( $health['label'], 'holds nothing' ), 'and is named for what is actually wrong' );

$GLOBALS['dpt_stub_options'] = array();

/* ---- who may switch the module itself ---- */

require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/modules/update-policy/class-dpt-up-module.php';

$module = new DPT_Update_Policy_Module();

$GLOBALS['dpt_stub_multisite'] = false;
dpt_test_ok( $module->user_can_toggle(), 'on a single site the switch is the Modules screen\'s own business' );

// On a network the switch decides for every site on it, so it asks for the
// authority that decision needs - not the authority the Modules screen
// happens to require. A main-site administrator can reach that screen with
// manage_options alone and would otherwise be able to switch off a hold they
// are not allowed to release.
$GLOBALS['dpt_stub_multisite']   = true;
$GLOBALS['dpt_stub_denied_caps'] = array( 'manage_network_options' );
dpt_test_ok( ! $module->user_can_toggle(), 'on a network a site administrator may not switch it' );
dpt_test_ok( '' !== $module->toggle_denied_reason(), 'and the screen is given a reason to show' );

$GLOBALS['dpt_stub_denied_caps'] = array();
dpt_test_ok( $module->user_can_toggle(), 'while a network administrator may' );
$GLOBALS['dpt_stub_multisite'] = false;

/* ---- standing down for the standalone plugin ---- */

// The module was extracted into a plugin of its own. A site with both would
// run two filters on one transient and show two screens saying the same
// thing, so when the standalone is present this module does nothing and says
// why on the Modules screen.
$GLOBALS['dpt_stub_filters'] = array();
$GLOBALS['dpt_stub_multisite'] = false;
dpt_test_ok( ! DPT_Update_Policy_Module::standalone_active(), 'without the standalone plugin the module is in charge' );
dpt_test_eq( $module->standing_down_reason(), '', 'and has nothing to explain' );
$module->init();
dpt_test_ok( dpt_stub_has_filter( 'site_transient_update_core' ), 'so it registers its hold' );

// The standalone was renamed for the WordPress.org directory, and a site
// that installed the earlier build still carries the old class. That state
// is asserted first, because a class declaration cannot be undone: once the
// new class exists in this process, the legacy-only site is unreachable.
// Declared inside a block so PHP does not hoist it above the assertions
// before this line.
if ( ! class_exists( 'Update_Policy_Core' ) ) {
	class Update_Policy_Core {}
}
$GLOBALS['dpt_stub_filters'] = array();
dpt_test_ok( DPT_Update_Policy_Module::standalone_active(), 'the pre-rename class counts as the standalone being present' );
$module->init();
dpt_test_ok( ! dpt_stub_has_filter( 'site_transient_update_core' ), 'so it registers nothing - one filter on the transient, not two' );

// And it is named as that build names itself. Someone who adopted it early
// has a menu item called Update Policy; sending them to look for Digitizer
// Update Hold is sending them to a screen their site does not have.
dpt_test_ok( false !== strpos( $module->standing_down_reason(), 'Settings > Update Policy' ), 'pointing a single site at the menu the legacy build actually has' );
dpt_test_ok( false === strpos( $module->standing_down_reason(), 'Digitizer Update Hold' ), 'and not at the name that build never used' );
$GLOBALS['dpt_stub_multisite'] = true;
dpt_test_ok( false !== strpos( $module->standing_down_reason(), 'Network Admin > Settings > Update Policy' ), 'and a network at Network Admin, still under the legacy name' );
$GLOBALS['dpt_stub_multisite'] = false;

// Now the renamed build, which is what any new installation has.
if ( ! class_exists( 'Digitizer_Update_Hold_Core' ) ) {
	class Digitizer_Update_Hold_Core {}
}
$GLOBALS['dpt_stub_filters'] = array();
dpt_test_ok( DPT_Update_Policy_Module::standalone_active(), 'the renamed class is seen too' );
$module->init();
dpt_test_ok( ! dpt_stub_has_filter( 'site_transient_update_core' ), 'and still nothing is registered' );
dpt_test_ok( '' !== $module->standing_down_reason(), 'while telling the Modules screen why' );
dpt_test_ok( false !== strpos( $module->standing_down_reason(), 'Settings > Digitizer Update Hold' ), 'pointing a single site at the current name' );
dpt_test_ok( false === strpos( $module->standing_down_reason(), 'Network Admin' ), 'and not at a network screen it does not have' );
$GLOBALS['dpt_stub_multisite'] = true;
dpt_test_ok( false !== strpos( $module->standing_down_reason(), 'Network Admin > Settings > Digitizer Update Hold' ), 'and a network at Network Admin, where the standalone actually keeps its screen' );
$GLOBALS['dpt_stub_multisite'] = false;

exit( dpt_test_summary() > 0 ? 1 : 0 );
