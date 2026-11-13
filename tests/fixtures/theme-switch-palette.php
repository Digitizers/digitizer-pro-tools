<?php
$root = isset($argv[1]) ? realpath($argv[1]) : false;
if (!$root || strpos($root, realpath('/private/tmp') . '/') !== 0 ||
    !is_file($root . '/wp-config.php') ||
    strpos(file_get_contents($root . '/wp-config.php'), 'DPT_THEME_FIXTURE') === false) {
    fwrite(STDERR, "Requires the disposable theme-switch fixture under /private/tmp\n"); exit(1);
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8876';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp-load.php';
$id = \Elementor\Plugin::instance()->kits_manager->create(array(
 'post_title'=>'DPT Fixture Kit', 'post_status'=>'publish',
 'settings'=>array(
  'system_colors'=>array(
   array('_id'=>'primary','title'=>'Primary','color'=>'#222222'),
   array('_id'=>'secondary','title'=>'Secondary','color'=>'#555555'),
   array('_id'=>'text','title'=>'Text','color'=>'#222222'),
   array('_id'=>'accent','title'=>'Accent','color'=>'#0066cc')
  ),
  'custom_colors'=>array(array('_id'=>'surface','title'=>'Surface','color'=>'#ffffff'))
 )
));
update_option('elementor_active_kit', $id);
// The fixture has no editor account; seed the saved Kit settings directly.
update_post_meta($id, '_elementor_page_settings', array(
 'system_colors'=>array(
  array('_id'=>'primary','title'=>'Primary','color'=>'#222222'),
  array('_id'=>'secondary','title'=>'Secondary','color'=>'#555555'),
  array('_id'=>'text','title'=>'Text','color'=>'#222222'),
  array('_id'=>'accent','title'=>'Accent','color'=>'#0066cc')
 ), 'custom_colors'=>array(array('_id'=>'surface','title'=>'Surface','color'=>'#ffffff'))
));
DPT_TS_Settings::save(array('base'=>'light','default'=>'system','colors'=>array(
 '--e-global-color-text'=>'#eeeeee', '--e-global-color-surface'=>'#111111'
)));
$page = wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'DPT Palette'));
update_post_meta($page,'_elementor_edit_mode','builder');
update_post_meta($page,'_elementor_template_type','wp-page');
update_post_meta($page,'_elementor_version',ELEMENTOR_VERSION);
$data = array(array('id'=>'abcdef1','elType'=>'container','settings'=>array(),'elements'=>array(
 array('id'=>'abcdef2','elType'=>'widget','widgetType'=>'heading','settings'=>array('title'=>'Global heading','__globals__'=>array('title_color'=>'globals/colors?id=text')),'elements'=>array()),
 array('id'=>'abcdef3','elType'=>'widget','widgetType'=>'heading','settings'=>array('title'=>'Fixed heading','title_color'=>'#cc0000'),'elements'=>array())
)));
update_post_meta($page,'_elementor_data',wp_slash(wp_json_encode($data)));
update_option('dpt_fixture_page',$page);
echo wp_json_encode(array('wordpress'=>get_bloginfo('version'),'elementor'=>ELEMENTOR_VERSION,'kit'=>$id,'page'=>$page,'palette'=>DPT_TS_Settings::elementor_colors(),'users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}")),JSON_PRETTY_PRINT) . "\n";
