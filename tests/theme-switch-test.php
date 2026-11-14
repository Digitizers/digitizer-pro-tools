<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-module.php';
require_once dirname( __DIR__ ) . '/includes/class-dpt-plugin.php';
function home_url( $path = '' ) { return 'https://example.test/site' . $path; }
function add_shortcode( $tag, $callback ) { $GLOBALS['ts_shortcodes'][ $tag ] = $callback; }
function wp_enqueue_script( $handle, $src, $deps, $version, $footer ) { $GLOBALS['ts_script'] = compact( 'handle', 'src', 'deps', 'version', 'footer' ); }
function wp_enqueue_style( $handle, $src, $deps, $version ) { $GLOBALS['ts_style'] = compact( 'handle', 'src' ); }
function wp_add_inline_script( $handle, $code, $position = 'after' ) { $GLOBALS['ts_inline_js'] = compact( 'handle', 'code', 'position' ); }
function wp_add_inline_style( $handle, $code ) { $GLOBALS['ts_inline_css'] = $code; }
require_once dirname( __DIR__ ) . '/modules/theme-switch/class-dpt-ts-module.php';

$module = new DPT_Theme_Switch_Module();
dpt_test_eq( DPT_Plugin::instance()->enabled_map()['theme_switch'], '0', 'new module is disabled by default' );
dpt_test_eq( $GLOBALS['dpt_stub_filters'], array(), 'loading and constructing the module registers no frontend hooks' );
$module->init();
dpt_test_ok( dpt_stub_has_filter( 'wp_enqueue_scripts' ), 'enabled module registers frontend assets' );
dpt_test_ok( isset( $GLOBALS['ts_shortcodes']['dpt_theme_switch'] ), 'enabled module registers shortcode' );
$GLOBALS['dpt_stub_options'][ DPT_TS_Settings::OPTION ] = array(
	'base' => 'light', 'default' => 'dark', 'colors' => array( '--surface' => '#111', '--text' => '#fff' ),
);
$module->enqueue();
dpt_test_eq( $GLOBALS['ts_script']['footer'], false, 'palette initializer runs synchronously in the head' );
dpt_test_eq( $GLOBALS['ts_script']['deps'], array(), 'frontend has no third-party script dependencies' );
dpt_test_eq( $GLOBALS['ts_inline_js']['position'], 'before', 'configuration precedes the initializer' );
dpt_test_ok( false !== strpos( $GLOBALS['ts_inline_css'], 'html[data-dpt-theme="dark"] body{' ), 'mapping reaches Elementor body-scoped tokens' );
dpt_test_ok( false !== strpos( $GLOBALS['ts_inline_css'], '--surface:#111;' ), 'explicit palette is emitted' );
dpt_test_eq( DPT_Theme_Switch_Module::palette_css( array( 'colors' => array() ) ), '', 'empty palette emits no override' );
dpt_test_ok( false !== strpos( DPT_Theme_Switch_Module::palette_css( array( 'base' => 'dark', 'colors' => array( '--text' => '#000' ) ) ), 'html[data-dpt-theme="light"]' ), 'dark base maps the opposite light mode' );
dpt_test_eq( DPT_Theme_Switch_Module::palette_css( array( 'colors' => array( '--evil</style>' => '#fff', '--x' => 'red;}' ) ) ), '', 'output revalidates dangerous CSS even when called directly' );
$html = $module->shortcode();
dpt_test_eq( substr_count( $html, '<button type="button"' ), 3, 'three native keyboard-operable buttons' );
dpt_test_eq( substr_count( $html, 'aria-pressed="false"' ), 3, 'each button exposes selection state' );
dpt_test_ok( false === strpos( $html, 'id=' ), 'multiple shortcodes create no duplicate IDs' );
dpt_test_ok( false !== strpos( $html, ' hidden>' ), 'no-JS fallback keeps inert controls hidden' );
$again = $module->shortcode();
dpt_test_eq( $again, $html, 'cached shortcode HTML never varies by visitor preference' );
$items = apply_filters( 'rocket_delay_js_exclusions', array( 'existing' ) );
dpt_test_ok( in_array( 'DPT_TS_CONFIG', $items, true ) && in_array( 'existing', $items, true ), 'narrow optimization exclusions preserve existing entries' );
$items = apply_filters( 'rocket_rucss_inline_atts_exclusions', array() );
dpt_test_ok( in_array( 'id="dpt-theme-switch-inline-css"', $items, true ), 'alternate CSS survives unused-CSS removal' );
exit( dpt_test_summary() ? 1 : 0 );
