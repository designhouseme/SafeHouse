<?php
/** Run with wp eval-file on an isolated local/development WordPress. Creates and deletes its own content. */
if (!defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    throw new RuntimeException('An isolated local/development WP-CLI environment is required.');
}
use SafeHouse\Core\ContentChanges;
use SafeHouse\Modules\LiteSpeed;
function shouse_cache_check(bool $pass, string $label): void {
    if (!$pass) { throw new RuntimeException($label); }
    WP_CLI::log('PASS '.$label);
}
shouse_cache_check(false !== has_action('init', [LiteSpeed::class, 'receive_purge']), 'purge receiver is registered globally');
shouse_cache_check(false !== has_action('shutdown', [LiteSpeed::class, 'finish_purge']), 'CLI purge dispatch is registered globally');
$server_varies = new ReflectionMethod(LiteSpeed::class, 'server_varies_ready');
$original_server_varies = $_SERVER['LSCACHE_VARY_COOKIE'] ?? null;
$rules = LiteSpeed::server_rules();
preg_match('/cache-vary:([^\"]+)/', $rules, $vary_names);
$_SERVER['LSCACHE_VARY_COOKIE'] = $vary_names[1] ?? '';
shouse_cache_check($server_varies->invoke(null), 'complete server-provided cookie rules enable caching');
unset($_SERVER['LSCACHE_VARY_COOKIE']);
$_SERVER['HTTP_LSCACHE_VARY_COOKIE'] = $vary_names[1] ?? '';
shouse_cache_check(!$server_varies->invoke(null), 'client-supplied vary header cannot enable caching');
unset($_SERVER['HTTP_LSCACHE_VARY_COOKIE']);
$_SERVER['LSCACHE_VARY_COOKIE'] = $vary_names[1] ?? '';
$bad_cookie = static fn() => 'invalid,cookie';
add_filter('woocommerce_cookie', $bad_cookie);
shouse_cache_check(!$server_varies->invoke(null) && str_starts_with(LiteSpeed::server_rules(), '#'), 'invalid custom cookie fails closed without injecting server rules');
remove_filter('woocommerce_cookie', $bad_cookie);
if (null === $original_server_varies) { unset($_SERVER['LSCACHE_VARY_COOKIE']); } else { $_SERVER['LSCACHE_VARY_COOKIE'] = $original_server_varies; }
$changes = [];
$listener = static function($urls) use (&$changes): void { $changes[] = $urls; };
add_action(ContentChanges::ACTION, $listener);
$ids = [];
$terms = [];
$assert_purge = static function(string $label) use (&$changes): void {
    shouse_cache_check(count($changes) > 0 && [] === array_values(array_filter($changes, static fn($urls) => [] !== $urls)), $label.' clears every page, including old URLs and pagination');
    $changes = [];
};
try {
    $id = wp_insert_post(['post_type'=>'post', 'post_title'=>'SafeHouse cache regression', 'post_status'=>'publish']);
    $ids[] = $id;
    $assert_purge('publishing');
    wp_update_post(['ID'=>$id, 'post_name'=>'shouse-cache-regression-renamed']);
    $assert_purge('slug change');
    $term = wp_insert_term('Cache regression '.wp_generate_uuid4(), 'category');
    if (is_wp_error($term)) { throw new RuntimeException($term->get_error_message()); }
    $terms[] = (int)$term['term_id'];
    wp_set_post_terms($id, $terms, 'category');
    $assert_purge('adding a term relationship');
    wp_set_post_terms($id, [], 'category');
    $assert_purge('removing the previous term relationship');
    wp_update_term($terms[0], 'category', ['slug'=>'cache-regression-'.wp_generate_uuid4()]);
    $assert_purge('term slug change');
    wp_delete_term(array_pop($terms), 'category');
    $assert_purge('term deletion');
    if (!post_type_exists('product')) { register_post_type('product', ['public'=>true]); }
    if (!post_type_exists('product_variation')) { register_post_type('product_variation', ['public'=>false]); }
    $product = wp_insert_post(['post_type'=>'product', 'post_title'=>'SafeHouse cache product', 'post_status'=>'publish']);
    $ids[] = $product;
    $variation = wp_insert_post(['post_type'=>'product_variation', 'post_parent'=>$product, 'post_status'=>'publish']);
    $ids[] = $variation;
    $changes = [];
    update_post_meta($product, '_price', '10');
    $assert_purge('direct price metadata change');
    update_post_meta($variation, '_sale_price', '7');
    $assert_purge('variation sale price change');
    update_post_meta($variation, '_stock_status', 'outofstock');
    $assert_purge('variation availability change');
    delete_post_meta($product, '_price');
    $assert_purge('price deletion');
    do_action('woocommerce_scheduled_sales');
    $assert_purge('scheduled sale');
    do_action('woocommerce_product_set_stock', new class($product) {
        public function __construct(private int $id) {}
        public function get_id(): int { return $this->id; }
        public function get_parent_id(): int { return 0; }
    });
    $assert_purge('stock affecting related listings');
    $draft = wp_insert_post(['post_type'=>'post', 'post_status'=>'draft', 'post_title'=>'SafeHouse cache draft']);
    $ids[] = $draft;
    shouse_cache_check([] === $changes, 'draft insertion does not clear public cache');
    wp_insert_comment(['comment_post_ID'=>$id, 'comment_content'=>'isolated spam test', 'comment_approved'=>'spam']);
    shouse_cache_check([] === $changes, 'unapproved comment does not clear public cache');
    wp_update_post(['ID'=>$id, 'post_status'=>'private']);
    $assert_purge('unpublishing');
} finally {
    remove_action(ContentChanges::ACTION, $listener);
    foreach (array_reverse($ids) as $id) { wp_delete_post($id, true); }
    foreach ($terms as $term) { wp_delete_term($term, 'category'); }
}
WP_CLI::success('Content cache hook regressions passed.');
