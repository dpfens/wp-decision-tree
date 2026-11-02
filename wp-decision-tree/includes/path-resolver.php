<?php
namespace BeerTree;

use WP_Error;

/**
 * Structural view of a tree from one node: its answer options and every
 * result node reachable beneath it. Knows nothing about what a result
 * means; the beer layer (BeerMenu) enriches results with post content
 * and attributes.
 */
final class PathResolver
{
    public function __construct(private Repository $repo) {}

    /**
     * @return array{
     *   node: array{id:int, type:string, prompt:string},
     *   options: array<int, array{label:string, node_id:int, sort_order:int}>,
     *   results: array<int, array{id:int, post_id:int, prompt:string, sort_order:int}>
     * }|WP_Error
     */
    public function resolve(int $treeId, int $nodeId, string $resultType): array|WP_Error
    {
        $node = $this->repo->getNode($treeId, $nodeId);
        if ($node === null) {
            return new WP_Error('bdt_not_found', 'Node not found in this tree.', ['status' => 404]);
        }

        $graph = $this->repo->loadGraph($treeId);

        $options = array_map(static fn (object $edge): array => [
            'label'      => (string) $edge->answer_label,
            'node_id'    => (int) $edge->to_node_id,
            'sort_order' => (int) $edge->sort_order,
        ], $this->repo->getEdgesFrom($treeId, $nodeId));

        $candidateIds = array_merge([$nodeId], $graph->descendants($nodeId));
        $rows         = $this->repo->getNodesByIds($treeId, $candidateIds, $resultType);

        $results = array_map(static fn (object $row): array => [
            'id'         => (int) $row->id,
            'post_id'    => (int) $row->post_id,
            'prompt'     => (string) $row->prompt,
            'sort_order' => (int) $row->sort_order,
        ], $rows);

        return [
            'node' => [
                'id'     => (int) $node->id,
                'type'   => (string) $node->node_type,
                'prompt' => (string) $node->prompt,
            ],
            'options' => $options,
            'results' => $results,
        ];
    }
}