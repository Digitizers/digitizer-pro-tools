<?php
// Disposable, account-free SQLite installation only. Run with the WordPress root.
$root = isset($argv[1]) ? realpath($argv[1]) : false;
if (!$root || strpos($root, realpath('/private/tmp') . '/') !== 0 ||
    !is_file($root . '/wp-content/db.php') || !is_file($root . '/wp-config.php') ||
    strpos(file_get_contents($root . '/wp-config.php'), 'DPT_THEME_FIXTURE') === false) {
    fwrite(STDERR, "Requires a marked disposable SQLite WordPress directory under /private/tmp\n"); exit(1);
}
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:8876';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
make_db_current_silent();
populate_options();
populate_roles();
update_option('siteurl', WP_SITEURL);
update_option('home', WP_HOME);
update_option('blogname', 'DPT disposable theme fixture');
update_option('blog_public', 0);
update_option('template', 'hello-elementor');
update_option('stylesheet', 'hello-elementor');
update_option('active_plugins', array('elementor/elementor.php', 'digitizer-pro-tools/digitizer-pro-tools.php'));
update_option('dpt_settings', array('modules' => array('theme_switch' => '1')));
echo "Local schema initialized; users: " . $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}") . "\n";
