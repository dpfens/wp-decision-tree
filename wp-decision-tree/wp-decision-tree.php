<?php
/**
 * Plugin Name: Item Decision Tree
 * Description: Guided item selection trees for breweries.
 * Version:     0.1.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BDT_VERSION', '0.1.0');
define('BDT_DIR', plugin_dir_path(__FILE__));

require_once BDT_DIR . 'includes/graph.php';
require_once BDT_DIR . 'includes/schema.php';
require_once BDT_DIR . 'includes/repository.php';
require_once BDT_DIR . 'includes/path-resolver.php';
require_once BDT_DIR . 'includes/admin/trait-post-form-helpers.php';
require_once BDT_DIR . 'includes/item/item-post-type.php';
require_once BDT_DIR . 'includes/item/item-menu.php';
require_once BDT_DIR . 'includes/admin/tree-editor.php';
require_once BDT_DIR . 'includes/admin/results-exporter.php';
require_once BDT_DIR . 'includes/item/attributes-page.php';
require_once BDT_DIR . 'includes/item/item-attributes-metabox.php';
require_once BDT_DIR . 'includes/intake/intake-store.php';
require_once BDT_DIR . 'includes/intake/intake-controller.php';
require_once BDT_DIR . 'includes/intake/intake-block.php';

(static function (): void {
    global $wpdb;

    $repo     = new ItemTree\Repository($wpdb);
    $resolver = new ItemTree\PathResolver($repo);
    $menu     = new ItemTree\Item\ItemMenu($resolver, $repo);

    // Needed on every request: the post type must exist everywhere, and its
    // delete hook has to fire from cron and WP-CLI too.
    (new ItemTree\Item\ItemPostType($repo))->register();

    // Public intake flow: the REST routes and block must exist on every request.
    $store = new ItemTree\Intake\IntakeStore($wpdb);
    (new ItemTree\Intake\IntakeController($repo, $menu, $store))->register();
    (new ItemTree\Intake\IntakeBlock($store))->register();

    if (is_admin()) {
        (new ItemTree\Admin\TreeEditor($repo, $menu, $store))->register();
        (new ItemTree\Admin\ResultsExporter($repo, $store, $menu))->register();
        (new ItemTree\Item\AttributesPage($repo))->register();
        (new ItemTree\Item\ItemAttributesMetabox($repo))->register();

        // Lets WordPress strip our notice params from the address bar after display.
        add_filter('removable_query_args', static fn (array $args): array => [...$args, 'bdt_notice', 'bdt_error']);
    }
})();

register_activation_hook(__FILE__, [ItemTree\Schema::class, 'install']);
add_action('plugins_loaded', [ItemTree\Schema::class, 'maybe_upgrade']);