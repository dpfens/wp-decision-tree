<?php
namespace BeerTree\Admin;

use BeerTree\Beer\BeerMenu;
use BeerTree\Beer\BeerPostType;
use BeerTree\Intake\IntakeStore;
use BeerTree\Repository;
use WP_Error;

final class TreeEditor
{
    use PostFormHelpers;

    private const CAP  = 'manage_options';
    private const PAGE = 'bdt-trees';

    public function __construct(
        private Repository $repo,
        private BeerMenu $menu,
        private IntakeStore $store
    ) {}

    public function register(): void
    {
        // Priority 5 so the top-level menu exists before the Beers post type
        // and the other submenus attach to it.
        add_action('admin_menu', [$this, 'menu'], 5);
        add_action('admin_post_bdt_create_tree',  [$this, 'handleCreateTree']);
        add_action('admin_post_bdt_add_question', [$this, 'handleAddQuestion']);
        add_action('admin_post_bdt_add_beer',     [$this, 'handleAddBeer']);
        add_action('admin_post_bdt_delete_node',  [$this, 'handleDeleteNode']);
        add_action('admin_post_bdt_add_edge',     [$this, 'handleAddEdge']);
        add_action('admin_post_bdt_delete_edge',  [$this, 'handleDeleteEdge']);
    }

    public function menu(): void
    {
        add_menu_page(
            'Decision Trees', 'Decision Trees', self::CAP,
            self::PAGE, [$this, 'render'], 'dashicons-randomize', 30
        );
    }

    /* ---------- Handlers ---------- */

    public function handleCreateTree(): void
    {
        $this->requireCapability('bdt_create_tree');
        $name   = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $result = $name === '' ? new WP_Error('bdt_empty', 'Name is required.') : $this->repo->createTree($name);
        $this->finish(is_wp_error($result) ? 0 : $result, $result, 'Tree created.');
    }

    public function handleAddQuestion(): void
    {
        $treeId = absint($_POST['tree_id'] ?? 0);
        $this->requireCapability('bdt_add_question_' . $treeId);

        $prompt = sanitize_text_field(wp_unslash($_POST['prompt'] ?? ''));

        $result = $prompt === ''
            ? new WP_Error('bdt_empty', 'Question text is required.')
            : $this->repo->createNode($treeId, 'question', $prompt);

        $this->finish($treeId, $result, 'Question added.');
    }

    public function handleAddBeer(): void
    {
        $treeId = absint($_POST['tree_id'] ?? 0);
        $this->requireCapability('bdt_add_beer_' . $treeId);

        $postId = absint($_POST['post_id'] ?? 0);
        $post   = $postId > 0 ? get_post($postId) : null;

        $result = ($post && $post->post_type === BeerPostType::POST_TYPE)
            ? $this->repo->createNode($treeId, BeerMenu::RESULT_TYPE, '', $postId)
            : new WP_Error('bdt_bad_beer', 'Choose a beer.');

        $this->finish($treeId, $result, 'Beer added to the tree.');
    }

    public function handleDeleteNode(): void
    {
        $treeId = absint($_POST['tree_id'] ?? 0);
        $nodeId = absint($_POST['node_id'] ?? 0);
        $this->requireCapability('bdt_delete_node_' . $nodeId);

        $this->finish($treeId, $this->repo->deleteNode($treeId, $nodeId), 'Node deleted.');
    }

    public function handleAddEdge(): void
    {
        $treeId = absint($_POST['tree_id'] ?? 0);
        $this->requireCapability('bdt_add_edge_' . $treeId);

        $result = $this->repo->addEdge(
            $treeId,
            absint($_POST['from_node_id'] ?? 0),
            absint($_POST['to_node_id'] ?? 0),
            sanitize_text_field(wp_unslash($_POST['label'] ?? ''))
        );
        $this->finish($treeId, $result, 'Connection added.');
    }

    public function handleDeleteEdge(): void
    {
        $treeId = absint($_POST['tree_id'] ?? 0);
        $edgeId = absint($_POST['edge_id'] ?? 0);
        $this->requireCapability('bdt_delete_edge_' . $edgeId);

        $this->repo->deleteEdge($treeId, $edgeId);
        $this->finish($treeId, true, 'Connection deleted.');
    }

    /** Redirects back to the editor with a notice. A WP_Error becomes an error notice. */
    private function finish(int $treeId, mixed $result, string $successMessage): void
    {
        $this->redirectWithNotice(
            self::PAGE,
            $treeId > 0 ? ['tree' => $treeId] : [],
            $result,
            $successMessage
        );
    }

    /* ---------- Rendering ---------- */

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $treeId = absint($_GET['tree'] ?? 0);

        $treeName = null;
        if ($treeId > 0) {
            foreach ($this->repo->listTrees() as $tree) {
                if ((int) $tree->id === $treeId) {
                    $treeName = $tree->name;
                    break;
                }
            }
        }

        if ($treeName !== null) {
            $this->openWrap($treeName, ['All trees', add_query_arg(['page' => self::PAGE], admin_url('admin.php'))]);
            $this->renderEditor($treeId);
        } else {
            $this->openWrap('Decision Trees');
            $this->renderList();
        }
        echo '</div>';
    }

    private function renderList(): void
    {
        $trees = $this->repo->listTrees();
        $stats = $this->store->treeStats(BeerMenu::RESULT_TYPE);

        $columns = [
            'questions'   => 'Questions',
            'beers'       => 'Beers',
            'connections' => 'Connections',
            'started'     => 'Started',
            'completed'   => 'Completed',
            'rate'        => 'Completion',
            'activity'    => 'Last activity',
        ];

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
           . '<th scope="col" class="column-primary">Name</th>';
        foreach ($columns as $label) {
            echo '<th scope="col" style="width:7.5em">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($trees === []) {
            echo $this->emptyRow(count($columns) + 1, 'No trees yet. Create one below.');
        }

        foreach ($trees as $tree) {
            $id  = (int) $tree->id;
            $s   = $stats[$id] ?? null;
            $url = add_query_arg(['page' => self::PAGE, 'tree' => $id], admin_url('admin.php'));

            $started   = $s ? (int) $s->started : 0;
            $completed = $s ? (int) $s->completed : 0;

            $cells = [
                'Questions'     => (string) ($s->questions ?? 0),
                'Beers'         => (string) ($s->beers ?? 0),
                'Connections'   => (string) ($s->connections ?? 0),
                'Started'       => (string) $started,
                'Completed'     => (string) $completed,
                'Completion'    => $started > 0 ? round($completed / $started * 100) . '%' : '—',
                'Last activity' => ($s && $s->idle_seconds !== null)
                    ? human_time_diff(time() - max(0, (int) $s->idle_seconds), time()) . ' ago'
                    : '—',
            ];

            printf('<tr><td class="column-primary" data-colname="Name"><strong><a class="row-title" href="%s">%s</a></strong></td>',
                esc_url($url), esc_html($tree->name));
            foreach ($cells as $label => $value) {
                printf('<td data-colname="%s">%s</td>', esc_attr($label), esc_html($value));
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Add new tree</h2>';
        $this->postForm('bdt_create_tree', 'bdt_create_tree', [],
            $this->formTable([
                ['Name', '<input type="text" id="bdt-tree-name" name="name" class="regular-text" required>', 'bdt-tree-name'],
            ]),
            'Create tree', null, 'primary');
    }

    private function renderEditor(int $treeId): void
    {
        $nodes  = $this->repo->listNodes($treeId);
        $edges  = $this->repo->listEdges($treeId);
        $labels = $this->nodeLabels($nodes);

        $this->renderResults($treeId);

        /* Nodes */
        echo '<h2>Nodes</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
           . '<th scope="col" style="width:5em">ID</th>'
           . '<th scope="col" style="width:10em">Type</th>'
           . '<th scope="col" class="column-primary">Text</th>'
           . '<th scope="col" style="width:8em">Actions</th>'
           . '</tr></thead><tbody>';
        if ($nodes === []) {
            echo $this->emptyRow(4, 'No nodes yet.');
        }
        foreach ($nodes as $n) {
            $id     = (int) $n->id;
            $isBeer = $n->node_type === BeerMenu::RESULT_TYPE;
            $text   = esc_html($labels[$id]);

            if ($isBeer && $n->post_id) {
                $editUrl = get_edit_post_link((int) $n->post_id, 'raw');
                if ($editUrl) {
                    $text = '<a href="' . esc_url($editUrl) . '">' . $text . '</a>';
                }
            }

            echo '<tr><td>' . $id . '</td>'
               . '<td>' . esc_html(ucfirst($n->node_type)) . '</td>'
               . '<td class="column-primary">' . $text . '</td><td>';
            $this->postForm('bdt_delete_node', 'bdt_delete_node_' . $id,
                ['tree_id' => $treeId, 'node_id' => $id], '',
                $isBeer ? 'Remove' : 'Delete',
                $isBeer
                    ? 'Remove this beer from the tree? The beer itself is not deleted.'
                    : 'Delete this question and all its connections?',
                'delete');
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h3>Add question</h3>';
        $this->postForm('bdt_add_question', 'bdt_add_question_' . $treeId, ['tree_id' => $treeId],
            $this->formTable([
                ['Question', '<input type="text" id="bdt-question" name="prompt" class="regular-text" required>',
                    'bdt-question', 'Short plain text, e.g. "How adventurous are you feeling?"'],
            ]),
            'Add question', null, 'primary');

        $this->renderAddBeer($treeId, $nodes);

        /* Connections */
        echo '<h2>Connections</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
           . '<th scope="col" class="column-primary">From</th>'
           . '<th scope="col">Answer</th>'
           . '<th scope="col">To</th>'
           . '<th scope="col" style="width:8em">Actions</th>'
           . '</tr></thead><tbody>';
        if ($edges === []) {
            echo $this->emptyRow(4, 'No connections yet.');
        }
        foreach ($edges as $e) {
            echo '<tr><td class="column-primary">' . esc_html($labels[(int) $e->from_node_id] ?? '?') . '</td>'
               . '<td>' . esc_html($e->answer_label) . '</td>'
               . '<td>' . esc_html($labels[(int) $e->to_node_id] ?? '?') . '</td><td>';
            $this->postForm('bdt_delete_edge', 'bdt_delete_edge_' . (int) $e->id,
                ['tree_id' => $treeId, 'edge_id' => (int) $e->id], '', 'Delete',
                'Delete this connection?', 'delete');
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        // Only questions can lead somewhere; beers are endpoints.
        $questions = array_filter($nodes, static fn (object $n): bool => $n->node_type !== BeerMenu::RESULT_TYPE);

        echo '<h3>Add connection</h3>';
        $this->postForm('bdt_add_edge', 'bdt_add_edge_' . $treeId, ['tree_id' => $treeId],
            $this->formTable([
                ['From', '<select id="bdt-edge-from" name="from_node_id">' . $this->nodeOptions($questions, $labels) . '</select>',
                    'bdt-edge-from', 'Only questions can lead to other nodes.'],
                ['Answer', '<input type="text" id="bdt-edge-label" name="label" class="regular-text">',
                    'bdt-edge-label', 'The answer a drinker picks to move from the first node to the second.'],
                ['To', '<select id="bdt-edge-to" name="to_node_id">' . $this->nodeOptions($nodes, $labels) . '</select>', 'bdt-edge-to'],
            ]),
            'Connect', null, 'primary');

        /* Preview */
        $previewId = absint($_GET['preview'] ?? 0);
        echo '<h2>Preview</h2>';
        echo '<form method="get">'
           . '<input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '">'
           . '<input type="hidden" name="tree" value="' . $treeId . '">'
           . '<label for="bdt-preview-node" class="screen-reader-text">Node to preview</label>'
           . '<select id="bdt-preview-node" name="preview">' . $this->nodeOptions($nodes, $labels, $previewId) . '</select> '
           . get_submit_button('Preview', 'secondary', '', false)
           . '</form>';

        if ($previewId > 0) {
            $resolved = $this->menu->forNode($treeId, $previewId);
            $json = is_wp_error($resolved)
                ? $resolved->get_error_message()
                : wp_json_encode($resolved, JSON_PRETTY_PRINT);
            echo '<p class="description">This is what visitors receive. Beers that are not published are left out.</p>';
            echo '<p><textarea readonly rows="16" class="large-text code">' . esc_textarea((string) $json) . '</textarea></p>';
        }
    }

    private function renderResults(int $treeId): void
    {
        $stats     = $this->store->treeStats(BeerMenu::RESULT_TYPE)[$treeId] ?? null;
        $started   = (int) ($stats->started ?? 0);
        $completed = (int) ($stats->completed ?? 0);

        echo '<h2>Results</h2>';
        printf('<p>%s started, %s completed.</p>', number_format_i18n($started), number_format_i18n($completed));
        $this->postForm('bdt_export_results', 'bdt_export_results_' . $treeId,
            ['tree_id' => $treeId], '', 'Export results (CSV)');
    }

    /** @param object[] $nodes */
    private function renderAddBeer(int $treeId, array $nodes): void
    {
        $inTree = array_map('intval', array_filter(
            array_column($nodes, 'post_id'),
            static fn ($v): bool => $v !== null
        ));

        $beers = get_posts([
            'post_type'      => BeerPostType::POST_TYPE,
            'post_status'    => ['publish', 'future', 'pending', 'draft', 'private'],
            'post__not_in'   => $inTree,
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        $newUrl = admin_url('post-new.php?post_type=' . BeerPostType::POST_TYPE);

        echo '<h3>Add beer</h3>';

        if ($beers === []) {
            printf('<p>No more beers to add. <a href="%s">Create a beer</a> first.</p>', esc_url($newUrl));
            return;
        }

        $options = '';
        foreach ($beers as $beer) {
            $suffix = '';
            if ($beer->post_status !== 'publish') {
                $status = get_post_status_object($beer->post_status);
                $suffix = ' — ' . ($status->label ?? $beer->post_status);
            }
            $options .= sprintf('<option value="%d">%s</option>',
                (int) $beer->ID, esc_html(($beer->post_title !== '' ? $beer->post_title : '(no title)') . $suffix));
        }

        $this->postForm('bdt_add_beer', 'bdt_add_beer_' . $treeId, ['tree_id' => $treeId],
            $this->formTable([
                ['Beer', '<select id="bdt-beer" name="post_id">' . $options . '</select>', 'bdt-beer',
                    'Beers are shared across trees. Only published beers are shown to visitors.'],
            ]),
            'Add beer', null, 'primary');

        printf('<p><a href="%s">Create a new beer</a></p>', esc_url($newUrl));
    }

    /* ---------- Helpers ---------- */

    /**
     * Display text for each node: the question text, or the beer post's title.
     *
     * @param object[] $nodes
     * @return array<int, string>  node ID => label
     */
    private function nodeLabels(array $nodes): array
    {
        $postIds = array_map('intval', array_filter(
            array_column($nodes, 'post_id'),
            static fn ($v): bool => $v !== null
        ));

        $titles = [];
        if ($postIds) {
            $posts = get_posts([
                'post_type'      => BeerPostType::POST_TYPE,
                'post_status'    => 'any',
                'post__in'       => $postIds,
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            ]);
            foreach ($posts as $post) {
                $titles[(int) $post->ID] = $post->post_title !== '' ? $post->post_title : '(no title)';
            }
        }

        $labels = [];
        foreach ($nodes as $n) {
            $labels[(int) $n->id] = $n->post_id
                ? ($titles[(int) $n->post_id] ?? '(unavailable beer)')
                : (string) $n->prompt;
        }
        return $labels;
    }

    /**
     * @param object[]           $nodes
     * @param array<int, string> $labels
     */
    private function nodeOptions(array $nodes, array $labels, int $selected = 0): string
    {
        $html = '';
        foreach ($nodes as $n) {
            $id    = (int) $n->id;
            $html .= sprintf('<option value="%d"%s>%s</option>',
                $id, selected($selected, $id, false), esc_html($labels[$id] ?? '?'));
        }
        return $html;
    }
}