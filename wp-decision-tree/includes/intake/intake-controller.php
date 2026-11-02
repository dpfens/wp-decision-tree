<?php
namespace BeerTree\Intake;

use BeerTree\Beer\BeerMenu;
use BeerTree\Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Public REST API behind the intake block. The server owns the walk through
 * the tree: the client may only pick an edge that leaves the session's
 * current node, so it cannot skip questions or jump to a result.
 *
 * Requests carry `show_matches`, taken from the block's setting. When true,
 * question steps also include the beers still reachable from where the
 * visitor is. That data is no more sensitive than the final results, so the
 * flag is trusted rather than stored per session.
 */
final class IntakeController
{
    private const NS = 'bdt/v1';

    public function __construct(
        private Repository $repo,
        private BeerMenu $menu,
        private IntakeStore $store
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $token   = ['type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9]{64}$'];
        $preview = ['type' => 'boolean', 'default' => false];
        $nodeId  = ['type' => 'integer', 'required' => true, 'minimum' => 1];

        // Tree names for the block's picker in the editor.
        register_rest_route(self::NS, '/trees', [
            'methods'             => 'GET',
            'callback'            => [$this, 'listTrees'],
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
        ]);

        $public = fn (string $path, string $callback, array $args) => register_rest_route(self::NS, $path, [
            'methods'             => 'POST',
            'callback'            => [$this, $callback],
            'permission_callback' => '__return_true',
            'args'                => $args,
        ]);

        $public('/intake/start',  'start',  ['tree_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1], 'show_matches' => $preview]);
        $public('/intake/resume', 'resume', ['token' => $token, 'show_matches' => $preview]);
        $public('/intake/answer', 'answer', ['token' => $token, 'to_node_id' => $nodeId, 'show_matches' => $preview]);
        $public('/intake/back',   'back',   ['token' => $token, 'show_matches' => $preview]);
        $public('/intake/finish', 'finish', ['token' => $token]);
        $public('/intake/reopen', 'reopen', ['token' => $token, 'show_matches' => $preview]);
    }

    /* ---------- Endpoints ---------- */

    public function listTrees(): WP_REST_Response
    {
        return rest_ensure_response(array_map(
            static fn (object $t): array => ['id' => (int) $t->id, 'name' => (string) $t->name],
            $this->repo->listTrees()
        ));
    }

    public function start(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $treeId = (int) $req['tree_id'];

        if (!$this->store->treeExists($treeId)) {
            return new WP_Error('bdt_no_tree', 'Questionnaire not found.', ['status' => 404]);
        }

        $limited = $this->throttle();
        if (is_wp_error($limited)) {
            return $limited;
        }

        $start = $this->store->getStartNode($treeId);
        if (!$start) {
            return new WP_Error('bdt_empty_tree', 'This questionnaire has no questions yet.', ['status' => 409]);
        }

        $token     = bin2hex(random_bytes(32));
        $sessionId = $this->store->createSession($treeId, hash('sha256', $token), (int) $start->id);
        if (is_wp_error($sessionId)) {
            return $sessionId;
        }

        $session = $this->store->getSessionByHash(hash('sha256', $token));
        return $this->respond($session, $this->wantsPreview($req), ['token' => $token]);
    }

    public function resume(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $session = $this->sessionFrom($req);
        return $session ? $this->respond($session, $this->wantsPreview($req)) : $this->badSession();
    }

    public function answer(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $session = $this->sessionFrom($req);
        if (!$session) {
            return $this->badSession();
        }
        if ($session->finished_at !== null) {
            return new WP_Error('bdt_finished', 'This questionnaire is already finished.', ['status' => 409]);
        }

        $treeId = (int) $session->tree_id;
        $from   = (int) $session->current_node_id;
        $to     = (int) $req['to_node_id'];

        $edge = $this->store->getEdgeBetween($treeId, $from, $to);
        if (!$edge) {
            return new WP_Error('bdt_bad_answer', 'That answer is not available.', ['status' => 400]);
        }

        $saved = $this->store->recordAnswer((int) $session->id, $treeId, $from, (int) $edge->id, $to);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $session->current_node_id = $to;
        return $this->respond($session, $this->wantsPreview($req));
    }

    public function back(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $session = $this->sessionFrom($req);
        if (!$session) {
            return $this->badSession();
        }

        $nodeId = $this->store->undoLastAnswer((int) $session->id);
        if (is_wp_error($nodeId)) {
            return $nodeId;
        }

        $session->current_node_id = $nodeId;
        $session->finished_at     = null;
        return $this->respond($session, $this->wantsPreview($req));
    }

    /** Ends the session where it stands and returns the full results. */
    public function finish(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $session = $this->sessionFrom($req);
        if (!$session) {
            return $this->badSession();
        }

        $this->store->finishSession((int) $session->id);
        $session->finished_at = 'finished';
        return $this->respond($session, false);
    }

    /** Picks up the questions again after stopping early. */
    public function reopen(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $session = $this->sessionFrom($req);
        if (!$session) {
            return $this->badSession();
        }

        $this->store->reopenSession((int) $session->id);
        $session->finished_at = null;
        return $this->respond($session, $this->wantsPreview($req));
    }

    /* ---------- Steps ---------- */

    private function sessionFrom(WP_REST_Request $req): ?object
    {
        return $this->store->getSessionByHash(hash('sha256', (string) $req['token']));
    }

    private function badSession(): WP_Error
    {
        return new WP_Error('bdt_bad_session', 'Your session has expired. Please start over.', ['status' => 404]);
    }

    private function wantsPreview(WP_REST_Request $req): bool
    {
        return (bool) $req['show_matches'];
    }

    private function respond(object $session, bool $preview, array $extra = []): WP_REST_Response|WP_Error
    {
        $step = $this->stepFor($session, $preview);
        if (is_wp_error($step)) {
            return $step;
        }
        return rest_ensure_response($extra + ['step' => $step, 'trail' => $this->trail($session)]);
    }

    /**
     * What the visitor gets: results if the session is finished (or the tree
     * ran out of questions), otherwise the current question.
     */
    private function stepFor(object $session, bool $preview): array|WP_Error
    {
        $treeId = (int) $session->tree_id;
        $nodeId = (int) $session->current_node_id;

        $node = $this->repo->getNode($treeId, $nodeId);
        if (!$node) {
            return new WP_Error('bdt_gone', 'This questionnaire has changed. Please start over.', ['status' => 410]);
        }

        $edges = $this->repo->getEdgesFrom($treeId, $nodeId);
        if ($session->finished_at !== null || $edges === []) {
            // Questions remain if there are edges: the visitor stopped early.
            return $this->finishedStep($session, $edges !== []);
        }

        $options = [];
        foreach ($edges as $edge) {
            $options[] = [
                'node_id' => (int) $edge->to_node_id,
                'label'   => $this->optionLabel($treeId, $edge),
            ];
        }

        $step = [
            'status'  => 'question',
            'prompt'  => (string) $node->prompt,
            'options' => $options,
        ];
        if ($preview) {
            $step['matches'] = $this->potentialMatches($session);
        }
        return $step;
    }

    /**
     * Beers still in play: reachable from the current question and satisfying
     * the filters of the answers so far. Later answers can only narrow this.
     * Light cards only; full content arrives with the final results.
     */
    private function potentialMatches(object $session): array
    {
        $sessionId = (int) $session->id;
        $groups    = $this->store->filterGroupsForSessions([$sessionId])[$sessionId] ?? [];

        $matched = $this->menu->matchingPostIds((int) $session->tree_id, (int) $session->current_node_id, $groups, false);
        if (is_wp_error($matched)) {
            return ['count' => 0, 'beers' => []];
        }

        $ids   = $matched['post_ids'];
        $limit = max(1, (int) apply_filters('bdt_intake_preview_limit', 24));
        $defs  = $this->attributeDefs();

        $beers = [];
        foreach ($this->menu->summaries(array_slice($ids, 0, $limit)) as $beer) {
            $beers[] = [
                'id'         => $beer['beer_id'],
                'name'       => $beer['name'],
                'image'      => $beer['image'],
                'attributes' => $this->attributeRows($beer['attributes'], $defs),
            ];
        }

        return ['count' => count($ids), 'beers' => $beers];
    }

    private function finishedStep(object $session, bool $stopped): array|WP_Error
    {
        $sessionId = (int) $session->id;
        $groups    = $this->store->filterGroupsForSessions([$sessionId])[$sessionId] ?? [];

        $resolved = $this->menu->forNode((int) $session->tree_id, (int) $session->current_node_id, $groups);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $this->store->finishSession($sessionId);

        $defs  = $this->attributeDefs();
        $beers = [];
        foreach ($resolved['beers'] as $beer) {
            $beers[] = [
                'id'         => $beer['beer_id'],
                'name'       => $beer['name'],
                'content'    => wp_kses_post($beer['content']),
                'image'      => $beer['image'],
                'attributes' => $this->attributeRows($beer['attributes'], $defs),
            ];
        }

        return [
            'status'  => 'done',
            'stopped' => $stopped,
            'relaxed' => $resolved['relaxed'] > 0,
            'beers'   => $beers,
        ];
    }

    /**
     * What the visitor has answered so far, rebuilt from the database so a
     * resumed session shows the same history.
     *
     * @return array<int, array{prompt:string, answer:string}>
     */
    private function trail(object $session): array
    {
        $out = [];
        foreach ($this->store->getAnswers((int) $session->id) as $row) {
            $answer = trim((string) $row->answer_label);
            if ($answer === '') {
                $answer = $this->optionLabel((int) $session->tree_id, (object) [
                    'answer_label' => '',
                    'to_node_id'   => (int) $row->to_node_id,
                ]);
            }
            $out[] = ['prompt' => (string) $row->prompt, 'answer' => $answer];
        }
        return $out;
    }

    /** @return array<string, object> attribute definitions keyed by slug */
    private function attributeDefs(): array
    {
        $defs = [];
        foreach ($this->repo->listAttributes() as $attr) {
            $defs[$attr->slug] = $attr;
        }
        return $defs;
    }

    /**
     * @param array<string, mixed>  $values  slug => typed value
     * @param array<string, object> $defs
     * @return array<int, array{label:string, value:string, unit:string}>
     */
    private function attributeRows(array $values, array $defs): array
    {
        $rows = [];
        foreach ($defs as $slug => $def) {
            if (!array_key_exists($slug, $values)) {
                continue;
            }
            $rows[] = [
                'label' => (string) $def->label,
                'value' => $this->formatValue($values[$slug]),
                'unit'  => (string) $def->unit,
            ];
        }
        return $rows;
    }

    /** The edge's answer text, or the destination's own text when the label was blank. */
    private function optionLabel(int $treeId, object $edge): string
    {
        $label = trim((string) $edge->answer_label);
        if ($label !== '') {
            return $label;
        }

        $target = $this->repo->getNode($treeId, (int) $edge->to_node_id);
        if ($target && $target->post_id) {
            $title = get_the_title((int) $target->post_id);
            if ($title !== '') {
                return $title;
            }
        }
        return $target && trim((string) $target->prompt) !== '' ? (string) $target->prompt : 'Next';
    }

    private function formatValue(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
    }

    /**
     * Rough per-IP limit on new sessions. Behind a reverse proxy REMOTE_ADDR
     * may be the proxy for everyone, so the limit is filterable.
     */
    private function throttle(): true|WP_Error
    {
        $limit = (int) apply_filters('bdt_intake_start_limit', 30);
        if ($limit <= 0) {
            return true;
        }

        $key   = 'bdt_start_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return new WP_Error('bdt_rate_limited', 'Too many attempts. Please try again in a few minutes.', ['status' => 429]);
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }
}
