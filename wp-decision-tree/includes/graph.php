<?php
namespace BeerTree;

use InvalidArgumentException;
use RuntimeException;
use SplQueue;

final class CycleException extends RuntimeException {}

final class Graph
{
    /** @var array<int, array<int, true>> node => [successor => true] */
    private array $out = [];

    /** @var array<int, array<int, true>> node => [predecessor => true] */
    private array $in = [];

    /**
     * Build a graph from a list of node IDs and [from, to] pairs.
     */
    public static function fromEdges(array $nodeIds, array $edges): self
    {
        $graph = new self();
        foreach ($nodeIds as $id) {
            $graph->addNode((int) $id);
        }
        foreach ($edges as [$from, $to]) {
            $graph->addEdge((int) $from, (int) $to);
        }
        return $graph;
    }

    public function addNode(int $id): void
    {
        $this->out[$id] ??= [];
        $this->in[$id]  ??= [];
    }

    /**
     * Adds the edge without a cycle check. Use wouldCreateCycle() first
     * when accepting user input, or hasCycle() to audit a loaded graph.
     */
    public function addEdge(int $from, int $to): void
    {
        $this->addNode($from);
        $this->addNode($to);
        $this->out[$from][$to] = true;
        $this->in[$to][$from]  = true;
    }

    public function hasNode(int $id): bool
    {
        return isset($this->out[$id]);
    }

    /** @return int[] */
    public function nodeIds(): array
    {
        return array_keys($this->out);
    }

    /**
     * True if adding from -> to would close a loop.
     * It does if `to` can already reach `from`.
     */
    public function wouldCreateCycle(int $from, int $to): bool
    {
        if ($from === $to) {
            return true;
        }
        return $this->canReach($to, $from);
    }

    /** Depth-first reachability: can $start get to $target? */
    public function canReach(int $start, int $target): bool
    {
        if (!$this->hasNode($start) || !$this->hasNode($target)) {
            return false;
        }

        $seen  = [$start => true];
        $stack = [$start];

        while ($stack) {
            $node = array_pop($stack);
            if ($node === $target) {
                return true;
            }
            foreach (array_keys($this->out[$node]) as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $stack[] = $next;
                }
            }
        }
        return false;
    }

    /**
     * Cycle detection using Kahn's algorithm. If any nodes remain
     * unsorted after the queue empties, they are part of a cycle.
     */
    public function hasCycle(): bool
    {
        return count($this->kahn()) !== count($this->out);
    }

    /**
     * Returns one cycle as a closed path, e.g. [3, 5, 9, 3], or null if the
     * graph is acyclic. Uses a depth-first search with three states:
     * unvisited, in progress (on the current path), and finished.
     * Reaching an in-progress node means the path back to it is a cycle.
     *
     * @return int[]|null
     */
    public function findCycle(): ?array
    {
        $state = [];   // node => 1 (in progress) | 2 (finished)
        $path  = [];   // current DFS path

        $ids = $this->nodeIds();
        sort($ids);    // deterministic result

        foreach ($ids as $start) {
            if (isset($state[$start])) {
                continue;
            }
            $cycle = $this->dfsForCycle($start, $state, $path);
            if ($cycle !== null) {
                return $cycle;
            }
        }
        return null;
    }

    /**
     * @param array<int, int> $state  by reference
     * @param int[]           $path   by reference
     * @return int[]|null
     */
    private function dfsForCycle(int $node, array &$state, array &$path): ?array
    {
        $state[$node] = 1;
        $path[]       = $node;

        $successors = array_keys($this->out[$node]);
        sort($successors);

        foreach ($successors as $next) {
            if (($state[$next] ?? 0) === 1) {
                // $next is already on the current path, so we have a loop.
                $offset = array_search($next, $path, true);
                return [...array_slice($path, (int) $offset), $next];
            }
            if (!isset($state[$next])) {
                $found = $this->dfsForCycle($next, $state, $path);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        array_pop($path);
        $state[$node] = 2;
        return null;
    }

    /**
     * Topological order: every node appears before all of its successors.
     * Ties are broken by ascending ID, so the output is deterministic.
     *
     * @return int[]
     * @throws CycleException if the graph is not a DAG
     */
    public function topologicalSort(): array
    {
        $order = $this->kahn();

        if (count($order) !== count($this->out)) {
            throw new CycleException('Graph contains a cycle; no topological order exists.');
        }
        return $order;
    }

    /**
     * Every node reachable from $id, excluding $id itself. Use this to
     * gather the beer-result nodes under whatever branch the user has reached.
     *
     * @return int[]
     */
    public function descendants(int $id): array
    {
        if (!$this->hasNode($id)) {
            throw new InvalidArgumentException("Unknown node: $id");
        }

        $seen  = [];
        $stack = array_keys($this->out[$id]);

        while ($stack) {
            $node = array_pop($stack);
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach (array_keys($this->out[$node]) as $next) {
                if (!isset($seen[$next])) {
                    $stack[] = $next;
                }
            }
        }
        return array_keys($seen);
    }

    /**
     * Kahn's algorithm. Returns the nodes that could be ordered.
     * On a valid DAG this contains every node.
     *
     * @return int[]
     */
    private function kahn(): array
    {
        $indegree = [];
        foreach ($this->in as $id => $preds) {
            $indegree[$id] = count($preds);
        }

        $ready = array_keys(array_filter($indegree, static fn (int $d) => $d === 0));
        sort($ready);

        $queue = new SplQueue();
        foreach ($ready as $id) {
            $queue->enqueue($id);
        }

        $order = [];
        while (!$queue->isEmpty()) {
            $node = $queue->dequeue();
            $order[] = $node;

            $successors = array_keys($this->out[$node]);
            sort($successors);
            foreach ($successors as $next) {
                if (--$indegree[$next] === 0) {
                    $queue->enqueue($next);
                }
            }
        }
        return $order;
    }
}