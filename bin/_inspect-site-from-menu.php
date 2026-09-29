<?php
$pages = get_posts(array(
  'post_type' => 'page',
  'post_status' => 'any',
  'posts_per_page' => 30,
  'meta_key' => '_dxai_ui_generated_page',
  'meta_value' => '1',
  'orderby' => 'ID',
  'order' => 'DESC',
));
echo "generated_pages=" . count($pages) . PHP_EOL;
foreach (array_slice($pages, 0, 8) as $p) {
  $id = $p->ID;
  $css = (string) get_post_meta($id, '_dxai_ui_css_url', true);
  $wrap = (string) get_post_meta($id, '_dxai_ui_wrapper_class', true);
  $content = (string) $p->post_content;
  $has_scope = (bool) preg_match('/dxai-ui--(\d+)/', $content, $m);
  $scope_id = $has_scope ? $m[1] : '-';
  $has_header_ref = str_contains($content, 'wp:template-part') && str_contains($content, 'header');
  $has_footer_ref = str_contains($content, 'wp:template-part') && str_contains($content, 'footer');
  $img_count = substr_count($content, '<img');
  echo "PAGE #$id {$p->post_name}\n";
  echo "  title={$p->post_title}\n";
  echo "  css=" . ($css !== '' ? 'yes' : 'NO') . " wrap=[$wrap] scope_class=dxai-ui--$scope_id\n";
  echo "  header_ref=" . ($has_header_ref?'1':'0') . " footer_ref=" . ($has_footer_ref?'1':'0') . " imgs=$img_count len=" . strlen($content) . "\n";
  // first 400 chars of content classes
  if (preg_match('/class="([^"]*dxai-ui[^"]*)"/', $content, $cm)) {
    echo "  group_class={$cm[1]}\n";
  }
}
$header = get_posts(array('post_type'=>'wp_template_part','name'=>'dxai-header','posts_per_page'=>5,'post_status'=>'publish'));
// find recent header parts
$parts = get_posts(array(
  'post_type' => 'wp_template_part',
  'posts_per_page' => 10,
  'post_status' => 'publish',
  'meta_key' => '_dxai_ui_generated',
  'meta_value' => '1',
  'orderby' => 'ID',
  'order' => 'DESC',
));
echo "\ntemplate_parts=" . count($parts) . PHP_EOL;
foreach (array_slice($parts, 0, 6) as $t) {
  $c = (string)$t->post_content;
  $hrefs = preg_match_all('/href="([^"]+)"/', $c, $hm) ? array_slice($hm[1], 0, 8) : array();
  echo "PART #{$t->ID} {$t->post_name} hrefs=" . implode(' | ', $hrefs) . PHP_EOL;
}
$navs = get_posts(array(
  'post_type' => 'wp_navigation',
  'posts_per_page' => 10,
  'post_status' => 'any',
  'meta_key' => '_dxai_ui_generated',
  'meta_value' => '1',
  'orderby' => 'ID',
  'order' => 'DESC',
));
echo "\nnavigations=" . count($navs) . PHP_EOL;
foreach (array_slice($navs, 0, 4) as $n) {
  echo "NAV #{$n->ID} {$n->post_title}\n" . substr($n->post_content, 0, 500) . "\n---\n";
}
$menus = wp_get_nav_menus();
echo "classic_menus=" . count($menus) . PHP_EOL;
foreach ($menus as $m) {
  $items = wp_get_nav_menu_items($m->term_id);
  echo "MENU {$m->name} (#{$m->term_id}) items=" . (is_array($items)?count($items):0) . PHP_EOL;
  if (is_array($items)) {
    foreach (array_slice($items, 0, 6) as $it) {
      echo "  - {$it->title} type={$it->type} object={$it->object} url={$it->url}\n";
    }
  }
}
