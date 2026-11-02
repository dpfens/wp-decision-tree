<?php
namespace BeerTree\Beer;

use BeerTree\Repository;
use WP_Post;

/**
 * Beers are posts: the title is the beer's name, the editor holds its rich
 * description, and the featured image is its photo. Typed details (ABV,
 * style, and so on) live in the plugin's attribute tables, keyed by post ID.
 *
 * Registered on every request, not just in admin, so that permanently
 * deleting a beer from cron or WP-CLI still cleans up its tree nodes.
 */
final class BeerPostType
{
    public const POST_TYPE = 'bdt_beer';

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
                'name'               => 'Beers',
                'singular_name'      => 'Beer',
                'add_new'            => 'Add New',
                'add_new_item'       => 'Add New Beer',
                'edit_item'          => 'Edit Beer',
                'new_item'           => 'New Beer',
                'view_item'          => 'View Beer',
                'search_items'       => 'Search Beers',
                'not_found'          => 'No beers found.',
                'not_found_in_trash' => 'No beers found in Trash.',
                'all_items'          => 'Beers',
                'item_published'     => 'Beer published.',
                'item_updated'       => 'Beer updated.',
            ],
            // No front-end URLs of its own; beers appear through the trees.
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

    /** Removes tree nodes, edges and attribute values when a beer is permanently deleted. */
    public function cleanup(int $postId, WP_Post $post): void
    {
        if ($post->post_type === self::POST_TYPE) {
            $this->repo->deleteNodesForPost($postId);
        }
    }
}