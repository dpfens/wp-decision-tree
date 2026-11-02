<?php
namespace BeerTree\Beer;

use BeerTree\PathResolver;
use BeerTree\Repository;
use WP_Error;
use WP_Post;

/**
 * Beer-specific view over the generic tree. Resolves the structural result
 * nodes under a node into beers: the post supplies the name, rich content
 * and image, and the attribute tables supply the typed details. Only
 * published beers are included, so setting a beer to draft or trash
 * removes it from every tree without touching the tree itself.
 */
final class BeerMenu
{
    public const RESULT_TYPE = 'beer';

    public function __construct(
        private PathResolver $resolver,
        private Repository $repo
    ) {}

    /**
     * `name` is plain text. `content` is rendered HTML, already run through
     * the_content filters (blocks, shortcodes, paragraphs).
     *
     * @return array{
     *   question: string,
     *   options: array,
     *   beers: array<int, array{
     *     id:int, beer_id:int, name:string, content:string,
     *     image:?string, attributes:array<string,mixed>, sort_order:int
     *   }>
     * }|WP_Error
     */
    public function forNode(int $treeId, int $nodeId): array|WP_Error
    {
        $resolved = $this->resolver->resolve($treeId, $nodeId, self::RESULT_TYPE);
        if ($resolved instanceof WP_Error) {
            return $resolved;
        }

        $postIds = array_values(array_unique(array_filter(
            array_map(static fn (array $r): int => $r['post_id'], $resolved['results'])
        )));

        $posts  = $this->publishedBeers($postIds);
        $values = $this->repo->getValuesForPosts(array_keys($posts));

        $beers = [];
        foreach ($resolved['results'] as $r) {
            $post = $posts[$r['post_id']] ?? null;
            if ($post === null) {
                continue; // draft, trashed or deleted
            }

            $beers[] = [
                'id'         => $r['id'],
                'beer_id'    => (int) $post->ID,
                'name'       => $post->post_title,
                'content'    => (string) apply_filters('the_content', $post->post_content),
                'image'      => get_the_post_thumbnail_url($post, 'large') ?: null,
                'attributes' => $values[(int) $post->ID] ?? [],
                'sort_order' => $r['sort_order'],
            ];
        }

        return [
            'question' => $resolved['node']['prompt'],
            'options'  => $resolved['options'],
            'beers'    => $beers,
        ];
    }

    /**
     * @param int[] $postIds
     * @return array<int, WP_Post>  keyed by post ID
     */
    private function publishedBeers(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $posts = get_posts([
            'post_type'      => BeerPostType::POST_TYPE,
            'post_status'    => 'publish',
            'post__in'       => $postIds,
            'posts_per_page' => -1,
            'orderby'        => 'post__in',
            'no_found_rows'  => true,
        ]);

        $out = [];
        foreach ($posts as $post) {
            $out[(int) $post->ID] = $post;
        }
        return $out;
    }
}