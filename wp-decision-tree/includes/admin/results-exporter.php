<?php
namespace BeerTree\Admin;

use BeerTree\Beer\BeerMenu;
use BeerTree\Beer\BeerPostType;
use BeerTree\CycleException;
use BeerTree\Intake\IntakeStore;
use BeerTree\Repository;

/**
 * Streams a tree's visitor results as CSV: one row per session, one column
 * per question (in tree order), plus the beers that session ended on.
 */
final class ResultsExporter
{
    use PostFormHelpers;

    private const BATCH = 500;

    public function __construct(
        private Repository $repo,
        private IntakeStore $store,
        private BeerMenu $menu
    ) {}

    public function register(): void
    {
        add_action('admin_post_bdt_export_results', [$this, 'handleExport']);
    }

    public function handleExport(): void
    {
        $treeId = absint($_POST['tree_id'] ?? 0);
        $this->requireCapability('bdt_export_results_' . $treeId);

        $treeName = null;
        foreach ($this->repo->listTrees() as $tree) {
            if ((int) $tree->id === $treeId) {
                $treeName = (string) $tree->name;
                break;
            }
        }
        if ($treeName === null) {
            wp_die('Tree not found.', 404);
        }

        $nodes       = $this->repo->listNodes($treeId);
        $labels      = $this->nodeLabels($nodes);
        $questionIds = $this->questionOrder($treeId, $nodes);

        $filename = sanitize_file_name(sprintf('%s-results-%s.csv', $treeName, gmdate('Y-m-d')));

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly

        $this->writeRow($out, [
            'Session ID', 'Started', 'Last activity', 'Finished',
            ...$this->questionHeaders($questionIds, $labels),
            'Recommended beers',
        ]);

        $beerCache = [];
        $titles    = [];
        $after     = 0;
        do {
            $sessions = $this->store->sessionsAfter($treeId, $after, self::BATCH);
            if ($sessions === []) {
                break;
            }
            $sessionIds = array_map(static fn (object $s): int => (int) $s->id, $sessions);
            $answers    = $this->store->answersForSessions($sessionIds);
            $groups     = $this->store->filterGroupsForSessions($sessionIds);

            foreach ($sessions as $session) {
                $sid = (int) $session->id;

                $byQuestion = [];
                foreach ($answers[$sid] ?? [] as $a) {
                    $byQuestion[(int) $a->from_node_id] = $this->answerText($a, $labels);
                }

                $row = [$sid, $session->started_at, $session->last_activity, $session->finished_at ?? ''];
                foreach ($questionIds as $qid) {
                    $row[] = $byQuestion[$qid] ?? '';
                }
                $row[] = $session->finished_at !== null
                    ? $this->beerNames($treeId, (int) $session->current_node_id, $groups[$sid] ?? [], $beerCache, $titles)
                    : '';

                $this->writeRow($out, $row);
            }

            $after = (int) end($sessions)->id;
            flush();
        } while (count($sessions) === self::BATCH);

        fclose($out);
        exit;
    }

    /* ---------- Helpers ---------- */

    /** Question node IDs in topological order, so columns follow the flow of the tree. */
    private function questionOrder(int $treeId, array $nodes): array
    {
        $questions = [];
        foreach ($nodes as $n) {
            if ($n->node_type === BeerMenu::QUESTION_TYPE) {
                $questions[(int) $n->id] = true;
            }
        }

        try {
            $order = $this->repo->loadGraph($treeId)->topologicalSort();
        } catch (CycleException) {
            $order = array_keys($questions);
        }

        return array_values(array_filter($order, static fn (int $id): bool => isset($questions[$id])));
    }

    /** Question text as the column title; a repeated text gets its node ID appended. */
    private function questionHeaders(array $questionIds, array $labels): array
    {
        $seen    = [];
        $headers = [];
        foreach ($questionIds as $id) {
            $title = trim($labels[$id] ?? '') !== '' ? $labels[$id] : 'Question ' . $id;
            if (isset($seen[$title])) {
                $title .= ' (#' . $id . ')';
            }
            $seen[$title] = true;
            $headers[]    = $title;
        }
        return $headers;
    }

    /** The edge's answer text, or the destination's own text if the label was blank. */
    private function answerText(object $answer, array $labels): string
    {
        $text = trim((string) $answer->answer_label);
        return $text !== '' ? $text : (string) ($labels[(int) $answer->to_node_id] ?? '');
    }

    /**
     * Beer names a session was shown: the beers at or beneath its final node,
     * narrowed by the filters its answers carried. Cached per node and filter
     * set; $titles caches post titles across rows.
     *
     * @param array<int, array<int, array{attr:string, op:string, value:mixed}>> $groups
     * @param array<string, string>                                              $cache
     * @param array<int, string>                                                 $titles
     */
    private function beerNames(int $treeId, int $nodeId, array $groups, array &$cache, array &$titles): string
    {
        $key = $nodeId . ':' . md5((string) wp_json_encode($groups));
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $matched = $this->menu->matchingPostIds($treeId, $nodeId, $groups);
        if (is_wp_error($matched)) {
            return $cache[$key] = '';
        }

        $missing = array_values(array_diff($matched['post_ids'], array_keys($titles)));
        if ($missing) {
            foreach (get_posts([
                'post_type'      => BeerPostType::POST_TYPE,
                'post_status'    => 'publish',
                'post__in'       => $missing,
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            ]) as $post) {
                $titles[(int) $post->ID] = $post->post_title !== '' ? $post->post_title : '(no title)';
            }
        }

        $names = [];
        foreach ($matched['post_ids'] as $id) {
            if (isset($titles[$id])) {
                $names[] = $titles[$id];
            }
        }
        return $cache[$key] = implode('; ', $names);
    }

    /** @return array<int, string> node ID => question text or beer title */
    private function nodeLabels(array $nodes): array
    {
        $postIds = array_values(array_filter(array_map(
            static fn (object $n): int => (int) $n->post_id,
            $nodes
        )));

        $titles = [];
        if ($postIds) {
            foreach (get_posts([
                'post_type'      => BeerPostType::POST_TYPE,
                'post_status'    => 'any',
                'post__in'       => $postIds,
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            ]) as $post) {
                $titles[(int) $post->ID] = $post->post_title;
            }
        }

        $labels = [];
        foreach ($nodes as $n) {
            $labels[(int) $n->id] = $n->post_id
                ? (string) ($titles[(int) $n->post_id] ?? '')
                : (string) $n->prompt;
        }
        return $labels;
    }

    /** Writes one CSV row, neutralising cells a spreadsheet would run as formulas. */
    private function writeRow($out, array $row): void
    {
        $cells = array_map(static function ($value): string {
            $text = (string) $value;
            return $text !== '' && strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
        }, $row);

        fputcsv($out, $cells, ',', '"', '');
    }
}
