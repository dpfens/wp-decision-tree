<?php
/**
 * Plugin Name: Beer Decision Tree
 * Description: Guided beer selection trees for breweries.
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
require_once BDT_DIR . 'includes/beer/beer-post-type.php';
require_once BDT_DIR . 'includes/beer/beer-menu.php';
require_once BDT_DIR . 'includes/admin/tree-editor.php';
require_once BDT_DIR . 'includes/admin/results-exporter.php';
require_once BDT_DIR . 'includes/beer/attributes-page.php';
require_once BDT_DIR . 'includes/beer/beer-attributes-metabox.php';
require_once BDT_DIR . 'includes/intake/intake-store.php';
require_once BDT_DIR . 'includes/intake/intake-controller.php';
require_once BDT_DIR . 'includes/intake/intake-block.php';

(static function (): void {
    global $wpdb;

    $repo     = new BeerTree\Repository($wpdb);
    $resolver = new BeerTree\PathResolver($repo);
    $menu     = new BeerTree\Beer\BeerMenu($resolver, $repo);

    // Needed on every request: the post type must exist everywhere, and its
    // delete hook has to fire from cron and WP-CLI too.
    (new BeerTree\Beer\BeerPostType($repo))->register();

    // Public intake flow: the REST routes and block must exist on every request.
    $store = new BeerTree\Intake\IntakeStore($wpdb);
    (new BeerTree\Intake\IntakeController($repo, $menu, $store))->register();
    (new BeerTree\Intake\IntakeBlock($store))->register();

    if (is_admin()) {
        (new BeerTree\Admin\TreeEditor($repo, $menu, $store))->register();
        (new BeerTree\Admin\ResultsExporter($repo, $store, $menu))->register();
        (new BeerTree\Beer\AttributesPage($repo))->register();
        (new BeerTree\Beer\BeerAttributesMetabox($repo))->register();

        // Lets WordPress strip our notice params from the address bar after display.
        add_filter('removable_query_args', static fn (array $args): array => [...$args, 'bdt_notice', 'bdt_error']);
    }
})();

register_activation_hook(__FILE__, [BeerTree\Schema::class, 'install']);
add_action('plugins_loaded', [BeerTree\Schema::class, 'maybe_upgrade']);