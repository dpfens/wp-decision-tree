<?php
namespace ItemTree\Intake;

use ItemTree\Item\ItemMenu;
use WP_Error;
use wpdb;

/** Persistence for visitor sessions and answers, plus the lookups the intake flow needs. */
final class IntakeStore
{
    public function __construct(private wpdb $db) {}

    private function t(string $name): string
    {
        return $this->db->prefix . 'bdt_' . $name;
    }

    public function treeExists(int $treeId): bool
    {
        return (bool) $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t('trees')} WHERE id = %d",
            $treeId
        ));
    }

    /**
     * The first question nobody points at: a question node with no incoming
     * edges. If a tree has several such roots, the lowest sort_order / ID wins.
     */
    public function getStartNode(int $treeId): ?object
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT n.id FROM {$this->t('nodes')} n
             LEFT JOIN {$this->t('edges')} e ON e.to_node_id = n.id AND e.tree_id = n.tree_id
             WHERE n.tree_id = %d AND n.node_type = %s AND e.id IS NULL
             ORDER BY n.sort_order ASC, n.id ASC
             LIMIT 1",
            $treeId, ItemMenu::QUESTION_TYPE
        ));
        return $row ?: null;
    }

    /** Looks the edge up by endpoints (unique key from_to), so clients never send edge IDs. */
    public function getEdgeBetween(int $treeId, int $from, int $to): ?object
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT id FROM {$this->t('edges')}
             WHERE tree_id = %d AND from_node_id = %d AND to_node_id = %d",
            $treeId, $from, $to
        ));
        return $row ?: null;
    }

    public function createSession(int $treeId, string $tokenHash, int $startNodeId): int|WP_Error
    {
        $ok = $this->db->insert(
            $this->t('sessions'),
            ['tree_id' => $treeId, 'token_hash' => $tokenHash, 'current_node_id' => $startNodeId],
            ['%d', '%s', '%d']
        );
        return $ok === false
            ? new WP_Error('bdt_db_error', 'Could not start a session.', ['status' => 500])
            : (int) $this->db->insert_id;
    }

    public function getSessionByHash(string $tokenHash): ?object
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT id, tree_id, current_node_id, finished_at
             FROM {$this->t('sessions')} WHERE token_hash = %s",
            $tokenHash
        ));
        return $row ?: null;
    }

    /**
     * Stores the answer and advances the session in one transaction. The
     * unique key (session_id, from_node_id) rejects a replayed or racing
     * second answer to the same question.
     */
    public function recordAnswer(int $sessionId, int $treeId, int $from, int $edgeId, int $to): true|WP_Error
    {
        $this->db->query('START TRANSACTION');

        $quiet = $this->db->suppress_errors(true);
        $ok = $this->db->insert(
            $this->t('responses'),
            [
                'session_id'   => $sessionId,
                'tree_id'      => $treeId,
                'from_node_id' => $from,
                'edge_id'      => $edgeId,
                'to_node_id'   => $to,
            ],
            ['%d', '%d', '%d', '%d', '%d']
        ) !== false;
        $this->db->suppress_errors($quiet);

        if (!$ok) {
            $this->db->query('ROLLBACK');
            return new WP_Error('bdt_already_answered', 'That question was already answered.', ['status' => 409]);
        }

        $ok = $this->db->query($this->db->prepare(
            "UPDATE {$this->t('sessions')}
             SET current_node_id = %d, last_activity = NOW()
             WHERE id = %d AND finished_at IS NULL",
            $to, $sessionId
        )) !== false;

        if (!$ok) {
            $this->db->query('ROLLBACK');
            return new WP_Error('bdt_db_error', 'Could not save your answer.', ['status' => 500]);
        }

        $this->db->query('COMMIT');
        return true;
    }

    /** Lets a visitor who stopped early carry on with the questions. */
    public function reopenSession(int $sessionId): void
    {
        $this->db->query($this->db->prepare(
            "UPDATE {$this->t('sessions')}
             SET finished_at = NULL, last_activity = NOW()
             WHERE id = %d",
            $sessionId
        ));
    }

    /** Idempotent: only the first call sets finished_at. */
    public function finishSession(int $sessionId): void
    {
        $this->db->query($this->db->prepare(
            "UPDATE {$this->t('sessions')}
             SET finished_at = NOW(), last_activity = NOW()
             WHERE id = %d AND finished_at IS NULL",
            $sessionId
        ));
    }

    /**
     * The session's answers in order, with the question text and the answer
     * label. LEFT JOINs keep a row even if the node or edge was deleted later.
     *
     * @return object[]  prompt, answer_label, to_node_id
     */
    public function getAnswers(int $sessionId): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT n.prompt, e.answer_label, r.to_node_id
             FROM {$this->t('responses')} r
             LEFT JOIN {$this->t('nodes')} n ON n.id = r.from_node_id
             LEFT JOIN {$this->t('edges')} e ON e.id = r.edge_id
             WHERE r.session_id = %d
             ORDER BY r.id ASC",
            $sessionId
        )) ?: [];
    }

    /**
     * Deletes the most recent answer and moves the session back to that
     * question, reopening it if it had finished.
     *
     * @return int|WP_Error  The node the session is now on
     */
    public function undoLastAnswer(int $sessionId): int|WP_Error
    {
        $this->db->query('START TRANSACTION');

        $last = $this->db->get_row($this->db->prepare(
            "SELECT id, from_node_id FROM {$this->t('responses')}
             WHERE session_id = %d ORDER BY id DESC LIMIT 1 FOR UPDATE",
            $sessionId
        ));
        if (!$last) {
            $this->db->query('ROLLBACK');
            return new WP_Error('bdt_nothing_to_undo', 'There is nothing to go back to.', ['status' => 409]);
        }

        $ok = $this->db->delete($this->t('responses'), ['id' => (int) $last->id], ['%d']) === 1;
        $ok = $ok && $this->db->query($this->db->prepare(
            "UPDATE {$this->t('sessions')}
             SET current_node_id = %d, finished_at = NULL, last_activity = NOW()
             WHERE id = %d",
            (int) $last->from_node_id, $sessionId
        )) !== false;

        if (!$ok) {
            $this->db->query('ROLLBACK');
            return new WP_Error('bdt_db_error', 'Could not go back.', ['status' => 500]);
        }

        $this->db->query('COMMIT');
        return (int) $last->from_node_id;
    }

    /**
     * Per-tree counts for the admin list, in one query.
     * idle_seconds is computed by the database so it uses the same clock that
     * wrote last_activity, whatever time zone that clock is in.
     *
     * @return array<int, object>  tree ID => questions, items, connections,
     *                             started, completed, idle_seconds (null if no sessions)
     */
    public function treeStats(): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT t.id,
                (SELECT COUNT(*) FROM {$this->t('nodes')} n
                  WHERE n.tree_id = t.id AND n.node_type = %s) AS questions,
                (SELECT COUNT(*) FROM {$this->t('nodes')} n
                  WHERE n.tree_id = t.id AND n.node_type = %s) AS items,
                (SELECT COUNT(*) FROM {$this->t('edges')} e
                  WHERE e.tree_id = t.id) AS connections,
                (SELECT COUNT(*) FROM {$this->t('sessions')} s
                  WHERE s.tree_id = t.id) AS started,
                (SELECT COUNT(*) FROM {$this->t('sessions')} s
                  WHERE s.tree_id = t.id AND s.finished_at IS NOT NULL) AS completed,
                (SELECT TIMESTAMPDIFF(SECOND, MAX(s.last_activity), NOW())
                   FROM {$this->t('sessions')} s WHERE s.tree_id = t.id) AS idle_seconds
             FROM {$this->t('trees')} t",
            ItemMenu::QUESTION_TYPE, ItemMenu::RESULT_TYPE
        )) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = $row;
        }
        return $out;
    }

    /**
     * A page of a tree's sessions in ID order, for streaming exports.
     *
     * @return object[]  id, started_at, last_activity, finished_at, current_node_id
     */
    public function sessionsAfter(int $treeId, int $afterId, int $limit): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT id, started_at, last_activity, finished_at, current_node_id
             FROM {$this->t('sessions')}
             WHERE tree_id = %d AND id > %d
             ORDER BY id ASC
             LIMIT %d",
            $treeId, $afterId, $limit
        )) ?: [];
    }

    /**
     * Answers for a set of sessions, in the order they were given.
     *
     * @param int[] $sessionIds
     * @return array<int, object[]>  session ID => rows of from_node_id, to_node_id, answer_label
     */
    public function answersForSessions(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $ph   = implode(',', array_fill(0, count($sessionIds), '%d'));
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT r.session_id, r.from_node_id, r.to_node_id, e.answer_label
             FROM {$this->t('responses')} r
             LEFT JOIN {$this->t('edges')} e ON e.id = r.edge_id
             WHERE r.session_id IN ($ph)
             ORDER BY r.id ASC",
            ...array_map('intval', $sessionIds)
        )) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->session_id][] = $row;
        }
        return $out;
    }

    /**
     * The attribute filters carried by the answers each session gave, grouped
     * per answer and oldest first. Answers with no filters are omitted.
     *
     * @param int[] $sessionIds
     * @return array<int, array<int, array<int, array{attr:string, op:string, value:mixed}>>>
     *         session ID => list of filter groups
     */
    public function filterGroupsForSessions(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $ph   = implode(',', array_fill(0, count($sessionIds), '%d'));
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT r.session_id, e.filters
             FROM {$this->t('responses')} r
             INNER JOIN {$this->t('edges')} e ON e.id = r.edge_id
             WHERE r.session_id IN ($ph) AND e.filters IS NOT NULL
             ORDER BY r.id ASC",
            ...array_map('intval', $sessionIds)
        )) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $filters = json_decode((string) $row->filters, true);
            if (is_array($filters) && $filters !== []) {
                $out[(int) $row->session_id][] = $filters;
            }
        }
        return $out;
    }
}
