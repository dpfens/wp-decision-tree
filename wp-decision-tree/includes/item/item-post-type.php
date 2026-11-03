<?php
namespace ItemTree\Item;

use ItemTree\Repository;
use WP_Post;

/**
 * Items are posts: the title is the item's name, the editor holds its rich
 * description, and the featured image is its photo. Typed details (ABV,
 * style, and so on) live in the plugin's attribute tables, keyed by post ID.
 *
 * Registered on every request, not just in admin, so that permanently
 * deleting an item from cron or WP-CLI still cleans up its tree nodes.
 */
final class ItemPostType
{
    public const POST_TYPE = 'bdt_item';

    /** Admin menu slug of the parent screen this type is listed under. */
    private const PARENT_MENU = 'bdt-trees';

    public function __construct(private Repository $repo) {}

    public function register(): void
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('before_delete_post', [$this, 'cleanup'], 10, 2);
    }

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => 'Items',
                'singular_name'      => 'Item',
                'add_new'            => 'Add New',
                'add_new_item'       => 'Add New Item',
                'edit_item'          => 'Edit Item',
                'new_item'           => 'New Item',
                'view_item'          => 'View Item',
                'search_items'       => 'Search Items',
                'not_found'          => 'No items found.',
                'not_found_in_trash' => 'No items found in Trash.',
                'all_items'          => 'Items',
                'item_published'     => 'Item published.',
                'item_updated'       => 'Item updated.',
            ],
            // No front-end URLs of its own; items appear through the trees.
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'rewrite'             => false,
            'query_var'           => false,
            'has_archive'         => false,

            'show_ui'           => true,
            'show_in_menu'      => self::PARENT_MENU,
            'show_in_admin_bar' => false,
            // Required for the block editor.
            'show_in_rest'      => true,

            'supports' => ['title', 'editor', 'thumbnail', 'revisions'],
        ]);
    }

    /** Removes tree nodes, edges and attribute values when an item is permanently deleted. */
    public function cleanup(int $postId, WP_Post $post): void
    {
        if ($post->post_type === self::POST_TYPE) {
            $this->repo->deleteNodesForPost($postId);
        }
    }
}