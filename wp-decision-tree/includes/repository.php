<?php
namespace BeerTree;

use WP_Error;
use wpdb;

final class Repository
{
    public function __construct(private wpdb $db) {}

    private function table(string $name): string
    {
        return $this->db->prefix . 'bdt_' . $name;
    }

    private function attrTable(): string
    {
        return $this->table('attributes');
    }

    private function valueTable(): string
    {
        return $this->table('beer_values');
    }

    /* ---------- Graph ---------- */

    public function loadGraph(int $treeId): Graph
    {
        $graph = new Graph();

        $nodeIds = $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->table('nodes')} WHERE tree_id = %d",
            $treeId
        ));
        foreach ($nodeIds as $id) {
            $graph->addNode((int) $id);
        }

        $edges = $this->db->get_results($this->db->prepare(
            "SELECT from_node_id, to_node_id FROM {$this->table('edges')} WHERE tree_id = %d",
            $treeId
        ));
        foreach ($edges as $edge) {
            $graph->addEdge((int) $edge->from_node_id, (int) $edge->to_node_id);
        }

        return $graph;
    }

    /** Audit helper: true if the stored tree is somehow cyclic. */
    public function isValidTree(int $treeId): bool
    {
        return !$this->loadGraph($treeId)->hasCycle();
    }

    /* ---------- Trees ---------- */

    public function listTrees(): array
    {
        return $this->db->get_results(
            "SELECT id, name FROM {$this->table('trees')} ORDER BY name"
        ) ?: [];
    }

    public function createTree(string $name): int|WP_Error
    {
        $ok = $this->db->insert($this->table('trees'), ['name' => $name], ['%s']);
        return $ok === false
            ? new WP_Error('bdt_db_error', $this->db->last_error)
            : (int) $this->db->insert_id;
    }

    /* ---------- Nodes ---------- */

    /**
     * Creates a node. Question nodes carry their text in $prompt. Result
     * nodes pass the ID of the post that holds their content; $prompt is
     * ignored for display in that case.
     *
     * @return int|WP_Error  New node ID
     */
    public function createNode(int $treeId, string $type, string $prompt, ?int $postId = null): int|WP_Error
    {
        if ($postId !== null) {
            $existing = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->table('nodes')} WHERE tree_id = %d AND post_id = %d",
                $treeId, $postId
            ));
            if ($existing) {
                return new WP_Error('bdt_dup_beer', 'That beer is already in this tree.');
            }
        }

        $ok = $this->db->insert(
            $this->table('nodes'),
            [
                'tree_id'   => $treeId,
                'node_type' => $type,
                'prompt'    => $prompt,
                'post_id'   => $postId,
            ],
            ['%d', '%s', '%s', '%d']
        );

        return $ok === false
            ? new WP_Error('bdt_db_error', $this->db->last_error ?: 'Could not create node.')
            : (int) $this->db->insert_id;
    }

    public function getNode(int $treeId, int $nodeId): ?object
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT id, node_type, prompt, post_id, sort_order FROM {$this->table('nodes')}
             WHERE id = %d AND tree_id = %d",
            $nodeId, $treeId
        ));
        return $row ?: null;
    }

    public function listNodes(int $treeId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT id, node_type, prompt, post_id, sort_order FROM {$this->table('nodes')}
             WHERE tree_id = %d ORDER BY sort_order ASC, id ASC",
            $treeId
        )) ?: [];
    }

    /**
     * Fetches nodes of one type from a set of IDs, in sort_order.
     *
     * @param int[] $ids
     * @return object[]
     */
    public function getNodesByIds(int $treeId, array $ids, string $type): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT id, node_type, prompt, post_id, sort_order
                FROM {$this->table('nodes')}
                WHERE tree_id = %d AND node_type = %s AND id IN ($placeholders)
                ORDER BY sort_order ASC, id ASC";

        return $this->db->get_results(
            $this->db->prepare($sql, $treeId, $type, ...array_map('intval', $ids))
        ) ?: [];
    }

    /** Deletes a node along with every edge that touches it. */
    public function deleteNode(int $treeId, int $nodeId): true|WP_Error
    {
        $this->db->query('START TRANSACTION');

        $edges = $this->db->query($this->db->prepare(
            "DELETE FROM {$this->table('edges')} WHERE tree_id = %d AND (from_node_id = %d OR to_node_id = %d)",
            $treeId, $nodeId, $nodeId
        ));
        $node = $this->db->delete($this->table('nodes'), ['id' => $nodeId, 'tree_id' => $treeId], ['%d', '%d']);

        if ($edges === false || $node === false) {
            $error = $this->db->last_error;
            $this->db->query('ROLLBACK');
            return new WP_Error('bdt_db_error', $error ?: 'Could not delete node.');
        }

        $this->db->query('COMMIT');
        return true;
    }

    /**
     * Removes everything that refers to a beer post: its nodes in every tree,
     * the edges touching those nodes, and its attribute values. Called when
     * the post is permanently deleted.
     */
    public function deleteNodesForPost(int $postId): true|WP_Error
    {
        $nodeIds = array_map('intval', $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->table('nodes')} WHERE post_id = %d",
            $postId
        )) ?: []);

        $this->db->query('START TRANSACTION');
        $ok = true;

        if ($nodeIds) {
            $ph = implode(',', array_fill(0, count($nodeIds), '%d'));
            $ok = $this->db->query($this->db->prepare(
                "DELETE FROM {$this->table('edges')}
                 WHERE from_node_id IN ($ph) OR to_node_id IN ($ph)",
                ...$nodeIds, ...$nodeIds
            )) !== false;

            $ok = $ok && $this->db->delete($this->table('nodes'), ['post_id' => $postId], ['%d']) !== false;
        }

        $ok = $ok && $this->db->delete($this->valueTable(), ['post_id' => $postId], ['%d']) !== false;

        if (!$ok) {
            $error = $this->db->last_error;
            $this->db->query('ROLLBACK');
            return new WP_Error('bdt_db_error', $error ?: 'Could not clean up beer data.');
        }

        $this->db->query('COMMIT');
        return true;
    }

    /* ---------- Edges ---------- */

    /**
     * Adds an answer edge after checking that both nodes belong to the tree
     * and that the edge would not create a cycle.
     *
     * @return int|WP_Error  New edge ID
     */
    public function addEdge(int $treeId, int $from, int $to, string $label = ''): int|WP_Error
    {
        $graph = $this->loadGraph($treeId);

        if (!$graph->hasNode($from) || !$graph->hasNode($to)) {
            return new WP_Error('bdt_unknown_node', 'Both nodes must belong to this tree.');
        }
        if ($graph->wouldCreateCycle($from, $to)) {
            return new WP_Error('bdt_cycle', 'That connection would create a loop.');
        }

        $ok = $this->db->insert(
            $this->table('edges'),
            [
                'tree_id'       => $treeId,
                'from_node_id'  => $from,
                'to_node_id'    => $to,
                'answer_label'  => $label,
            ],
            ['%d', '%d', '%d', '%s']
        );

        return $ok === false
            ? new WP_Error('bdt_db_error', $this->db->last_error ?: 'Could not add edge.')
            : (int) $this->db->insert_id;
    }

    /** @return object[] Outgoing edges in display order. */
    public function getEdgesFrom(int $treeId, int $nodeId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT to_node_id, answer_label, sort_order FROM {$this->table('edges')}
             WHERE tree_id = %d AND from_node_id = %d
             ORDER BY sort_order ASC, id ASC",
            $treeId, $nodeId
        )) ?: [];
    }

    public function listEdges(int $treeId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT id, from_node_id, to_node_id, answer_label FROM {$this->table('edges')}
             WHERE tree_id = %d ORDER BY from_node_id ASC, sort_order ASC, id ASC",
            $treeId
        )) ?: [];
    }

    public function deleteEdge(int $treeId, int $edgeId): void
    {
        $this->db->delete($this->table('edges'), ['id' => $edgeId, 'tree_id' => $treeId], ['%d', '%d']);
    }

    /* ---------- Attribute definitions ---------- */

    public function defineAttribute(
        string $slug,
        string $label,
        string $valueType,
        ?string $unit = null,
        ?array $enumOptions = null
    ): int|WP_Error {
        if (!in_array($valueType, ['string', 'number', 'boolean', 'enum'], true)) {
            return new WP_Error('bdt_bad_type', "Unknown value type: $valueType");
        }
        if ($valueType === 'enum' && !$enumOptions) {
            return new WP_Error('bdt_no_options', 'Choice attributes need at least one option.');
        }

        if ($existing = $this->getAttribute($slug)) {
            return (int) $existing->id;
        }

        $ok = $this->db->insert(
            $this->attrTable(),
            [
                'slug'         => $slug,
                'label'        => $label,
                'value_type'   => $valueType,
                'unit'         => $unit,
                'enum_options' => $enumOptions ? wp_json_encode(array_values($enumOptions)) : null,
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
        return $ok === false
            ? new WP_Error('bdt_db_error', $this->db->last_error)
            : (int) $this->db->insert_id;
    }

    public function getAttribute(string $slug): ?object
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT id, slug, label, value_type, unit, enum_options
             FROM {$this->attrTable()} WHERE slug = %s",
            $slug
        ));
        return $row ?: null;
    }

    public function getAttributeById(int $attributeId): ?object
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT id, slug, label, value_type, unit, enum_options
             FROM {$this->attrTable()} WHERE id = %d",
            $attributeId
        ));
        return $row ?: null;
    }

    /** @return object[] */
    public function listAttributes(): array
    {
        return $this->db->get_results(
            "SELECT id, slug, label, value_type, unit, enum_options
             FROM {$this->attrTable()} ORDER BY label ASC"
        ) ?: [];
    }

    public function deleteAttribute(int $attributeId): true|WP_Error
    {
        $this->db->query('START TRANSACTION');
        $this->db->delete($this->valueTable(), ['attribute_id' => $attributeId], ['%d']);
        $deleted = $this->db->delete($this->attrTable(), ['id' => $attributeId], ['%d']);

        if ($deleted === false) {
            $this->db->query('ROLLBACK');
            return new WP_Error('bdt_db_error', $this->db->last_error);
        }
        $this->db->query('COMMIT');
        return true;
    }

    /**
     * How many beers use each choice of an attribute.
     *
     * @return array<string, int>  option => count
     */
    public function enumUsage(int $attributeId): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT value_string, COUNT(*) AS n FROM {$this->valueTable()}
             WHERE attribute_id = %d AND value_string IS NOT NULL
             GROUP BY value_string",
            $attributeId
        )) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->value_string] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Updates an attribute's label and unit. For Choice attributes it also
     * replaces the option list: renamed options are carried over to stored
     * values, and removed options have their stored values cleared.
     * Slug and type are immutable.
     *
     * @param array<int, array{original:string,label:string}>|null $options
     *        Choice rows in display order. 'original' is the option's
     *        current name ('' for a newly added row).
     */
    public function updateAttribute(int $attributeId, string $label, ?string $unit, ?array $options = null): true|WP_Error
    {
        $attr = $this->getAttributeById($attributeId);
        if (!$attr) {
            return new WP_Error('bdt_not_found', 'Attribute not found.');
        }

        $data    = ['label' => $label, 'unit' => $unit];
        $formats = ['%s', '%s'];
        $renames = [];   // list of [from, to]
        $removed = [];   // list of option names

        if ($attr->value_type === 'enum') {
            $old    = array_map('strval', json_decode((string) $attr->enum_options, true) ?: []);
            $labels = [];
            $kept   = [];

            foreach ($options ?? [] as $o) {
                $original = $o['original'];
                // A "new" row typed with an existing name is that option, not a new one.
                if ($original === '' && in_array($o['label'], $old, true)) {
                    $original = $o['label'];
                }
                $labels[] = $o['label'];

                if ($original !== '' && in_array($original, $old, true)) {
                    $kept[] = $original;
                    if ($original !== $o['label']) {
                        $renames[] = [$original, $o['label']];
                    }
                }
            }

            if ($labels === []) {
                return new WP_Error('bdt_no_options', 'Choice attributes need at least one option.');
            }
            if (count($labels) !== count(array_unique($labels))) {
                return new WP_Error('bdt_dup_option', 'Each option must be unique.');
            }

            $removed              = array_values(array_diff($old, $kept));
            $data['enum_options'] = wp_json_encode($labels);
            $formats[]            = '%s';
        }

        $this->db->query('START TRANSACTION');
        $ok = true;

        // Deletes first, so a removed name can safely be reused as a rename target.
        if ($removed) {
            $ph = implode(',', array_fill(0, count($removed), '%s'));
            $ok = $this->db->query($this->db->prepare(
                "DELETE FROM {$this->valueTable()} WHERE attribute_id = %d AND value_string IN ($ph)",
                $attributeId, ...$removed
            )) !== false;
        }

        // One statement, so swaps (A to B while B to A) read the old values.
        if ($ok && $renames) {
            $cases = '';
            $args  = [];
            foreach ($renames as [$from, $to]) {
                $cases .= ' WHEN %s THEN %s';
                $args[] = $from;
                $args[] = $to;
            }
            $ph   = implode(',', array_fill(0, count($renames), '%s'));
            $args = [...$args, $attributeId, ...array_column($renames, 0)];

            $ok = $this->db->query($this->db->prepare(
                "UPDATE {$this->valueTable()}
                 SET value_string = CASE value_string{$cases} ELSE value_string END
                 WHERE attribute_id = %d AND value_string IN ($ph)",
                ...$args
            )) !== false;
        }

        if ($ok) {
            $ok = $this->db->update($this->attrTable(), $data, ['id' => $attributeId], $formats, ['%d']) !== false;
        }

        if (!$ok) {
            $error = $this->db->last_error;
            $this->db->query('ROLLBACK');
            return new WP_Error('bdt_db_error', $error ?: 'Could not update attribute.');
        }

        $this->db->query('COMMIT');
        return true;
    }

    /* ---------- Beer attribute values ---------- */

    /**
     * Loads all attributes for a set of beer posts in one query.
     *
     * @param int[] $postIds
     * @return array<int, array<string, mixed>>  postId => [slug => typed value]
     */
    public function getValuesForPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT v.post_id, a.slug, a.value_type, v.value_string, v.value_number
             FROM {$this->valueTable()} v
             JOIN {$this->attrTable()} a ON a.id = v.attribute_id
             WHERE v.post_id IN ($placeholders)",
            ...array_map('intval', $postIds)
        )) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->post_id][$r->slug] = match ($r->value_type) {
                'number'  => (float) $r->value_number,
                'boolean' => (bool) (float) $r->value_number,
                default   => (string) $r->value_string,
            };
        }
        return $out;
    }

    /**
     * Query helper: beer post IDs where a numeric attribute satisfies a comparison.
     * $op is one of '=', '<', '<=', '>', '>='.
     *
     * @return int[]
     */
    public function postIdsWhereNumber(string $slug, string $op, float $value): array
    {
        if (!in_array($op, ['=', '<', '<=', '>', '>='], true)) {
            return [];
        }
        $attr = $this->getAttribute($slug);
        if (!$attr) {
            return [];
        }
        return array_map('intval', $this->db->get_col($this->db->prepare(
            "SELECT post_id FROM {$this->valueTable()}
             WHERE attribute_id = %d AND value_number $op %f",
            (int) $attr->id, $value
        )) ?: []);
    }

    /**
     * Sets one attribute on one beer. Validates against the declared type,
     * and for enums against the declared options.
     */
    public function setValue(int $postId, string $slug, string|int|float|bool $value): true|WP_Error
    {
        $attr = $this->getAttribute($slug);
        if (!$attr) {
            return new WP_Error('bdt_unknown_attr', "No attribute defined for '$slug'.");
        }

        if (in_array($attr->value_type, ['number', 'boolean'], true)) {
            $number = is_bool($value) ? (int) $value : $value;
            if (!is_numeric($number)) {
                return new WP_Error('bdt_bad_value', "'$slug' expects a number.");
            }
            $sql = "INSERT INTO {$this->valueTable()} (post_id, attribute_id, value_string, value_number)
                    VALUES (%d, %d, NULL, %f)
                    ON DUPLICATE KEY UPDATE value_string = NULL, value_number = VALUES(value_number)";
            $args = [$postId, (int) $attr->id, (float) $number];
        } else {
            $string = (string) $value;
            if ($attr->value_type === 'enum') {
                $allowed = json_decode((string) $attr->enum_options, true) ?: [];
                if (!in_array($string, $allowed, true)) {
                    return new WP_Error('bdt_bad_option', "'$string' is not an option for '$slug'.");
                }
            }
            $sql = "INSERT INTO {$this->valueTable()} (post_id, attribute_id, value_string, value_number)
                    VALUES (%d, %d, %s, NULL)
                    ON DUPLICATE KEY UPDATE value_string = VALUES(value_string), value_number = NULL";
            $args = [$postId, (int) $attr->id, $string];
        }

        $ok = $this->db->query($this->db->prepare($sql, ...$args));
        return $ok === false ? new WP_Error('bdt_db_error', $this->db->last_error) : true;
    }

    public function clearValue(int $postId, string $slug): true|WP_Error
    {
        $attr = $this->getAttribute($slug);
        if (!$attr) {
            return true;
        }
        $ok = $this->db->delete(
            $this->valueTable(),
            ['post_id' => $postId, 'attribute_id' => (int) $attr->id],
            ['%d', '%d']
        );
        return $ok === false ? new WP_Error('bdt_db_error', $this->db->last_error) : true;
    }
}