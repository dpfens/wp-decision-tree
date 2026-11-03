<?php
namespace ItemTree\Item;

use ItemTree\PathResolver;
use ItemTree\Repository;
use WP_Error;
use WP_Post;

/**
 * Item-specific view over the generic tree. Works out which items a visitor
 * gets at a node and enriches them: the post supplies the name, rich content
 * and image, and the attribute tables supply the typed details.
 *
 * Candidate items come from two kinds of end node: an item node names one
 * item, and a pool node stands for every published item. Answer filters
 * (attribute conditions collected along the visitor's path) then narrow the
 * candidates. Only published items are ever included, so setting an item to
 * draft or trash removes it from every tree without touching the tree itself.
 */
final class ItemMenu
{
    public const QUESTION_TYPE = 'question';
    public const RESULT_TYPE   = 'item';
    public const POOL_TYPE     = 'pool';

    public function __construct(
        private PathResolver $resolver,
        private Repository $repo
    ) {}

    /**
     * `name` is plain text. `content` is rendered HTML, already run through
     * the_content filters (blocks, shortcodes, paragraphs).
     *
     * $filterGroups holds one list of conditions per answered question that
     * carried filters, oldest first. If nothing matches all of them, the
     * newest group is dropped, then the next, until something matches;
     * `relaxed` is how many groups had to be dropped.
     *
     * @param array<int, array<int, array{attr:string, op:string, value:mixed}>> $filterGroups
     * @return array{
     *   question: string,
     *   options: array,
     *   relaxed: int,
     *   items: array<int, array{
     *     item_id:int, name:string, content:string,
     *     image:?string, attributes:array<string,mixed>
     *   }>
     * }|WP_Error
     */
    public function forNode(int $treeId, int $nodeId, array $filterGroups = []): array|WP_Error
    {
        $resolved = $this->resolver->resolve($treeId, $nodeId, [self::RESULT_TYPE, self::POOL_TYPE]);
        if ($resolved instanceof WP_Error) {
            return $resolved;
        }

        $matched = $this->select($resolved['results'], $filterGroups);
        $postIds = $matched['post_ids'];

        $posts  = $this->postsById($postIds);
        $values = $this->repo->getValuesForPosts($postIds);

        $items = [];
        foreach ($postIds as $postId) {
            $post = $posts[$postId] ?? null;
            if ($post === null) {
                continue;
            }

            $items[] = [
                'item_id'    => $postId,
                'name'       => $post->post_title,
                'content'    => (string) apply_filters('the_content', $post->post_content),
                'image'      => get_the_post_thumbnail_url($post, 'large') ?: null,
                'attributes' => $values[$postId] ?? [],
            ];
        }

        return [
            'question' => $resolved['node']['prompt'],
            'options'  => $resolved['options'],
            'relaxed'  => $matched['relaxed'],
            'items'    => $items,
        ];
    }

    /**
     * Just the IDs: published item posts for a node after filtering, in
     * display order. Cheap enough for reports; forNode() adds the content.
     * With $relax false, an empty result stays empty instead of dropping
     * filters, which is what a "matches so far" preview wants.
     *
     * @param array<int, array<int, array{attr:string, op:string, value:mixed}>> $filterGroups
     * @return array{post_ids:int[], relaxed:int}|WP_Error
     */
    public function matchingPostIds(int $treeId, int $nodeId, array $filterGroups = [], bool $relax = true): array|WP_Error
    {
        $resolved = $this->resolver->resolve($treeId, $nodeId, [self::RESULT_TYPE, self::POOL_TYPE]);
        if ($resolved instanceof WP_Error) {
            return $resolved;
        }
        return $this->select($resolved['results'], $filterGroups, $relax);
    }

    /**
     * Light cards for a list of item IDs: no rendered content, a medium image.
     * Used for previews, where full content for every item would be heavy.
     *
     * @param int[] $postIds
     * @return array<int, array{item_id:int, name:string, image:?string, attributes:array<string,mixed>}>
     */
    public function summaries(array $postIds): array
    {
        $posts  = $this->postsById($postIds);
        $values = $this->repo->getValuesForPosts($postIds);

        $out = [];
        foreach ($postIds as $postId) {
            $post = $posts[$postId] ?? null;
            if ($post === null) {
                continue;
            }
            $out[] = [
                'item_id'    => $postId,
                'name'       => $post->post_title,
                'image'      => get_the_post_thumbnail_url($post, 'medium') ?: null,
                'attributes' => $values[$postId] ?? [],
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{type:string, post_id:int}> $results
     * @param array<int, array<int, array{attr:string, op:string, value:mixed}>> $filterGroups
     * @return array{post_ids:int[], relaxed:int}
     */
    private function select(array $results, array $filterGroups, bool $relax = true): array
    {
        $explicit = [];
        $hasPool  = false;
        foreach ($results as $r) {
            if ($r['type'] === self::POOL_TYPE) {
                $hasPool = true;
            } elseif ($r['post_id'] > 0) {
                $explicit[] = $r['post_id'];
            }
        }

        // Hand-placed items first, in tree order; pool items after, by title.
        $candidates = $this->publishedIds(array_values(array_unique($explicit)));
        if ($hasPool) {
            $candidates = [...$candidates, ...array_values(array_diff($this->allPublishedIds(), $candidates))];
        }

        $groups  = array_values(array_filter($filterGroups, static fn (array $g): bool => $g !== []));
        $relaxed = 0;

        while (true) {
            $conditions = array_merge(...$groups);
            $ids        = $conditions === [] ? $candidates : $this->applyConditions($candidates, $conditions);

            if ($ids !== [] || $groups === [] || !$relax) {
                break;
            }
            array_pop($groups);
            $relaxed++;
        }

        return ['post_ids' => $ids, 'relaxed' => $relaxed];
    }

    /**
     * @param int[] $candidates
     * @param array<int, array{attr:string, op:string, value:mixed}> $conditions
     * @return int[]
     */
    private function applyConditions(array $candidates, array $conditions): array
    {
        $allowed = $this->repo->postIdsMatching($conditions);
        if ($allowed === null) {
            return $candidates;
        }

        $allowed = array_flip($allowed);
        return array_values(array_filter($candidates, static fn (int $id): bool => isset($allowed[$id])));
    }

    /**
     * @param int[] $postIds
     * @return int[]  the published ones, in the order given
     */
    private function publishedIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        return array_map('intval', get_posts([
            'post_type'      => ItemPostType::POST_TYPE,
            'post_status'    => 'publish',
            'post__in'       => $postIds,
            'posts_per_page' => -1,
            'orderby'        => 'post__in',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]));
    }

    /** @return int[] every published item, by title */
    private function allPublishedIds(): array
    {
        return array_map('intval', get_posts([
            'post_type'      => ItemPostType::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]));
    }

    /**
     * @param int[] $postIds
     * @return array<int, WP_Post>  keyed by post ID
     */
    private function postsById(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $out = [];
        foreach (get_posts([
            'post_type'      => ItemPostType::POST_TYPE,
            'post_status'    => 'publish',
            'post__in'       => $postIds,
            'posts_per_page' => -1,
            'orderby'        => 'post__in',
            'no_found_rows'  => true,
        ]) as $post) {
            $out[(int) $post->ID] = $post;
        }
        return $out;
    }
}
