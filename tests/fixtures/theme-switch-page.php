<?php
// Copy into the disposable WordPress root as probe.php; excluded from releases.
if (!is_file(__DIR__ . '/wp-config.php') || strpos(file_get_contents(__DIR__ . '/wp-config.php'), 'DPT_THEME_FIXTURE') === false) {
    http_response_code(404); exit;
}
require __DIR__ . '/wp-load.php';
$id = get_option('dpt_fixture_page');
wp(array('page_id'=>$id));
\Elementor\Plugin::instance()->frontend->init();
$content = \Elementor\Plugin::instance()->frontend->get_builder_content($id);
?><!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8">
<?php wp_head(); ?>
<style>body{background:var(--e-global-color-surface);color:var(--e-global-color-text);padding:24px}#child-token{--e-global-color-text:#00aa00;color:var(--e-global-color-text)}</style>
</head><body <?php body_class(); ?>>
<header><?php echo do_shortcode('[dpt_theme_switch]'); ?></header>
<main><?php echo $content; ?><p id="child-token">Local override</p></main>
<footer><?php echo do_shortcode('[dpt_theme_switch]'); ?></footer>
<?php wp_footer(); ?>
</body></html>
