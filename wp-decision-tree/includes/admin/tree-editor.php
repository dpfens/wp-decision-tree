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

    private const TYPE_LABELS = [
        BeerMenu::QUESTION_TYPE => 'Question',
        BeerMenu::RESULT_TYPE   => 'Beer',
        BeerMenu::POOL_TYPE     => 'Beer pool',
    ];

    private const OP_SYMBOLS = ['=' => '=', '!=' => '≠', '<' => '<', '<=' => '≤', '>' => '>', '>=' => '≥'];

    private string $hook = '';

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
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_bdt_add_pool',     [$this, 'handleAddPool']);
        add_action('admin_post_bdt_create_tree',  [$this, 'handleCreateTree']);
        add_action('admin_post_bdt_add_question', [$this, 'handleAddQuestion']);
        add_action('admin_post_bdt_add_beer',     [$this, 'handleAddBeer']);
        add_action('admin_post_bdt_delete_node',  [$this, 'handleDeleteNode']);
        add_action('admin_post_bdt_add_edge',     [$this, 'handleAddEdge']);
        add_action('admin_post_bdt_delete_edge',  [$this, 'handleDeleteEdge']);
    }

    public function menu(): void
    {
        $this->hook = (string) add_menu_page(
            'Decision Trees', 'Decision Trees', self::CAP,
            self::PAGE, [$this, 'render'], 'dashicons-randomize', 30
        );
    }

    /** Script and styles for the answer-filter builder on the editor screen. */
    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hook) {
            return;
        }

        wp_register_script('bdt-tree-editor', false, [], BDT_VERSION, true);
        wp_enqueue_script('bdt-tree-editor');
        wp_add_inline_script('bdt-tree-editor', <<<'JS'
(function () {
    var box = document.querySelector('[data-bdt-filters]');
    if (!box) { return; }

    var attrs = JSON.parse(box.dataset.attrs || '[]');
    var OPS = {
        number:  ['=', '!=', '<', '<=', '>', '>='],
        boolean: ['='],
        enum:    ['=', '!='],
        string:  ['=', '!=']
    };
    var LABELS = { '=': 'is', '!=': 'is not', '<': 'is less than', '<=': 'is at most', '>': 'is more than', '>=': 'is at least' };
    var list = box.querySelector('.bdt-filter-list');
    var next = 0;

    function find(slug) {
        return attrs.filter(function (a) { return a.slug === slug; })[0];
    }

    function addRow() {
        var i = next++;
        var li = document.createElement('li');
        li.className = 'bdt-filter';

        var sel = document.createElement('select');
        sel.name = 'filters[' + i + '][attr]';
        sel.setAttribute('aria-label', 'Attribute');
        sel.add(new Option('Choose attribute…', ''));
        attrs.forEach(function (a) { sel.add(new Option(a.label, a.slug)); });

        var op = document.createElement('select');
        op.name = 'filters[' + i + '][op]';
        op.setAttribute('aria-label', 'Condition');

        var val = document.createElement('span');
        val.className = 'bdt-filter-value';

        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'button-link button-link-delete';
        rm.textContent = 'Remove';
        rm.addEventListener('click', function () { li.remove(); });

        function sync() {
            var a = find(sel.value);
            op.innerHTML = '';
            val.innerHTML = '';
            op.hidden = !a;
            if (!a) { return; }

            OPS[a.type].forEach(function (o) { op.add(new Option(LABELS[o], o)); });

            var field;
            if (a.type === 'enum') {
                field = document.createElement('select');
                a.options.forEach(function (o) { field.add(new Option(o, o)); });
            } else if (a.type === 'boolean') {
                field = document.createElement('select');
                field.add(new Option('Yes', '1'));
                field.add(new Option('No', '0'));
            } else {
                field = document.createElement('input');
                field.type = a.type === 'number' ? 'number' : 'text';
                if (a.type === 'number') { field.step = 'any'; field.className = 'small-text'; }
            }
            field.name = 'filters[' + i + '][value]';
            field.setAttribute('aria-label', 'Value');
            val.appendChild(field);

            if (a.unit) {
                var u = document.createElement('span');
                u.className = 'description';
                u.textContent = ' ' + a.unit;
                val.appendChild(u);
            }
        }

        sel.addEventListener('change', sync);
        sync();

        li.append(sel, ' ', op, ' ', val, ' ', rm);
        list.appendChild(li);
        sel.focus();
    }

    box.querySelector('.bdt-add-filter').addEventListener('click', addRow);
})();
JS);

        wp_add_inline_style('common', <<<'CSS'
.bdt-filter-list { margin: 0 0 .5em; padding: 0; list-style: none; }
.bdt-filter { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 0 0 6px; }
CSS);
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
            : $this->repo->createNode($treeId, BeerMenu::QUESTION_TYPE, $prompt);

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

    public function handleAddPool(): void
    {
        $treeId = absint($_POST['tree_id'] ?? 0);
        $this->requireCapability('bdt_add_pool_' . $treeId);

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

        $this->finish(
            $treeId,
            $this->repo->createNode($treeId, BeerMenu::POOL_TYPE, $name !== '' ? $name : 'Matching beers'),
            'Beer pool added.'
        );
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
            sanitize_text_field(wp_unslash($_POST['label'] ?? '')),
            self::postedFilters()
        );
        $this->finish($treeId, $result, 'Connection added.');
    }

    /**
     * Reads the posted filters[i][attr|op|value] rows. Only shape and
     * sanitising happen here; Repository::normalizeFilters() validates them
     * against the attribute definitions.
     *
     * @return array<int, array{attr:string, op:string, value:string}>
     */
    private static function postedFilters(): array
    {
        $raw = wp_unslash($_POST['filters'] ?? []);
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'attr'  => sanitize_key(is_string($row['attr'] ?? null) ? $row['attr'] : ''),
                'op'    => is_string($row['op'] ?? null) ? $row['op'] : '',   // whitelisted by the repository
                'value' => is_string($row['value'] ?? null) ? sanitize_text_field($row['value']) : '',
            ];
        }
        return $rows;
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
        $stats = $this->store->treeStats();

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

        $attributes = $this->repo->listAttributes();
        $bySlug     = [];
        foreach ($attributes as $attr) {
            $bySlug[$attr->slug] = $attr;
        }

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
            $isPool = $n->node_type === BeerMenu::POOL_TYPE;
            $text   = esc_html($labels[$id]);

            if ($isPool) {
                $text .= '<br><span class="description">Every published beer that fits the answers given.</span>';
            }

            if ($isBeer && $n->post_id) {
                $editUrl = get_edit_post_link((int) $n->post_id, 'raw');
                if ($editUrl) {
                    $text = '<a href="' . esc_url($editUrl) . '">' . $text . '</a>';
                }
            }

            echo '<tr><td>' . $id . '</td>'
               . '<td>' . esc_html(self::TYPE_LABELS[$n->node_type] ?? ucfirst($n->node_type)) . '</td>'
               . '<td class="column-primary">' . $text . '</td><td>';
            if ($isBeer) {
                [$button, $confirm] = ['Remove', 'Remove this beer from the tree? The beer itself is not deleted.'];
            } elseif ($isPool) {
                [$button, $confirm] = ['Delete', 'Delete this beer pool and all its connections?'];
            } else {
                [$button, $confirm] = ['Delete', 'Delete this question and all its connections?'];
            }
            $this->postForm('bdt_delete_node', 'bdt_delete_node_' . $id,
                ['tree_id' => $treeId, 'node_id' => $id], '', $button, $confirm, 'delete');
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
        $this->renderAddPool($treeId);

        /* Connections */
        echo '<h2>Connections</h2>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
           . '<th scope="col" class="column-primary">From</th>'
           . '<th scope="col">Answer</th>'
           . '<th scope="col">To</th>'
           . '<th scope="col">Filters</th>'
           . '<th scope="col" style="width:8em">Actions</th>'
           . '</tr></thead><tbody>';
        if ($edges === []) {
            echo $this->emptyRow(5, 'No connections yet.');
        }
        foreach ($edges as $e) {
            echo '<tr><td class="column-primary">' . esc_html($labels[(int) $e->from_node_id] ?? '?') . '</td>'
               . '<td>' . esc_html($e->answer_label) . '</td>'
               . '<td>' . esc_html($labels[(int) $e->to_node_id] ?? '?') . '</td>'
               . '<td>' . esc_html($this->describeFilters($e->filters, $bySlug)) . '</td><td>';
            $this->postForm('bdt_delete_edge', 'bdt_delete_edge_' . (int) $e->id,
                ['tree_id' => $treeId, 'edge_id' => (int) $e->id], '', 'Delete',
                'Delete this connection?', 'delete');
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        // Only questions can lead somewhere; beers and pools are endpoints.
        $questions = array_filter($nodes, static fn (object $n): bool => $n->node_type === BeerMenu::QUESTION_TYPE);

        echo '<h3>Add connection</h3>';
        $this->postForm('bdt_add_edge', 'bdt_add_edge_' . $treeId, ['tree_id' => $treeId],
            $this->formTable([
                ['From', '<select id="bdt-edge-from" name="from_node_id">' . $this->nodeOptions($questions, $labels) . '</select>',
                    'bdt-edge-from', 'Only questions can lead to other nodes.'],
                ['Answer', '<input type="text" id="bdt-edge-label" name="label" class="regular-text">',
                    'bdt-edge-label', 'The answer a drinker picks to move from the first node to the second.'],
                ['To', '<select id="bdt-edge-to" name="to_node_id">' . $this->nodeOptions($nodes, $labels) . '</select>', 'bdt-edge-to'],
                ['Filters', $this->filtersEditor($attributes), '',
                    'Optional. Beers shown at the end must satisfy the filters of every answer given on the way.'],
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
            echo '<p class="description">This is what visitors receive from this node. Unpublished beers are left out. The preview applies no answer filters, so pools show every published beer.</p>';
            echo '<p><textarea readonly rows="16" class="large-text code">' . esc_textarea((string) $json) . '</textarea></p>';
        }
    }

    private function renderResults(int $treeId): void
    {
        $stats     = $this->store->treeStats()[$treeId] ?? null;
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

    private function renderAddPool(int $treeId): void
    {
        echo '<h3>Add beer pool</h3>';
        $this->postForm('bdt_add_pool', 'bdt_add_pool_' . $treeId, ['tree_id' => $treeId],
            $this->formTable([
                ['Name', '<input type="text" id="bdt-pool-name" name="name" class="regular-text" placeholder="Matching beers">',
                    'bdt-pool-name', 'A pool ends a path with every published beer that fits the visitor\'s answers, so new beers appear without editing the tree. Put filters on the connections that lead to it.'],
            ]),
            'Add beer pool', null, 'secondary');
    }

    /* ---------- Helpers ---------- */

    /**
     * Repeater for answer filters. The rows are built by the inline script
     * from the attribute definitions passed in data-attrs.
     *
     * @param object[] $attributes
     */
    private function filtersEditor(array $attributes): string
    {
        if ($attributes === []) {
            $url = add_query_arg(['page' => \BeerTree\Beer\AttributesPage::PAGE], admin_url('admin.php'));
            return '<p class="description">No attributes yet. <a href="' . esc_url($url) . '">Define attributes</a> to filter beers by them.</p>';
        }

        $data = array_map(static fn (object $a): array => [
            'slug'    => $a->slug,
            'label'   => $a->label,
            'type'    => $a->value_type,
            'unit'    => (string) $a->unit,
            'options' => array_map('strval', json_decode((string) $a->enum_options, true) ?: []),
        ], $attributes);

        return '<div data-bdt-filters data-attrs="' . esc_attr((string) wp_json_encode($data)) . '">'
             . '<ul class="bdt-filter-list"></ul>'
             . '<button type="button" class="button bdt-add-filter">Add filter</button>'
             . '</div>';
    }

    /**
     * Readable summary of an edge's filters, e.g. "ABV ≤ 5 %; Style = IPA".
     *
     * @param array<string, object> $bySlug  attributes keyed by slug
     */
    private function describeFilters(?string $json, array $bySlug): string
    {
        $filters = json_decode((string) $json, true);
        if (!is_array($filters) || $filters === []) {
            return '';
        }

        $parts = [];
        foreach ($filters as $f) {
            $attr  = $bySlug[$f['attr'] ?? ''] ?? null;
            $label = $attr ? $attr->label : ($f['attr'] ?? '?') . ' (deleted)';
            $value = $f['value'] ?? '';
            $value = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
            $unit  = $attr && $attr->unit ? ' ' . $attr->unit : '';

            $parts[] = $label . ' ' . (self::OP_SYMBOLS[$f['op'] ?? ''] ?? '?') . ' ' . $value . $unit;
        }
        return implode('; ', $parts);
    }

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