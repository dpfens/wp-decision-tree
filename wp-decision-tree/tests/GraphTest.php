<?php
use BeerTree\CycleException;
use BeerTree\Graph;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/graph.php';

final class GraphTest extends TestCase
{
    public function test_topological_order_respects_every_edge(): void
    {
        $edges = [[1, 2], [1, 3], [2, 4], [3, 4]];
        $graph = Graph::fromEdges([1, 2, 3, 4], $edges);
        $pos   = array_flip($graph->topologicalSort());

        foreach ($edges as [$from, $to]) {
            $this->assertLessThan($pos[$to], $pos[$from]);
        }
    }

    public function test_detects_cycle_and_refuses_to_sort(): void
    {
        $graph = Graph::fromEdges([1, 2, 3], [[1, 2], [2, 3], [3, 1]]);

        $this->assertTrue($graph->hasCycle());
        $this->expectException(CycleException::class);
        $graph->topologicalSort();
    }

    public function test_would_create_cycle_checks_before_insert(): void
    {
        $graph = Graph::fromEdges([1, 2, 3], [[1, 2], [2, 3]]);

        $this->assertTrue($graph->wouldCreateCycle(3, 1));
        $this->assertFalse($graph->wouldCreateCycle(1, 3));
        $this->assertTrue($graph->wouldCreateCycle(2, 2));
    }

    public function test_descendants_supports_partial_results(): void
    {
        $graph = Graph::fromEdges([1, 2, 3, 4], [[1, 2], [2, 3], [1, 4]]);

        $this->assertEqualsCanonicalizing([2, 3, 4], $graph->descendants(1));
        $this->assertEqualsCanonicalizing([3], $graph->descendants(2));
    }

    public function test_find_cycle_returns_closed_path(): void
    {
        $graph = Graph::fromEdges([1, 2, 3, 4], [[1, 2], [2, 3], [3, 2], [3, 4]]);

        $cycle = $graph->findCycle();

        $this->assertNotNull($cycle);
        $this->assertSame($cycle[0], $cycle[array_key_last($cycle)]); // closed
        $this->assertEqualsCanonicalizing([2, 3], array_unique($cycle));
    }

    public function test_find_cycle_returns_null_for_dag(): void
    {
        $this->assertNull(Graph::fromEdges([1, 2, 3], [[1, 2], [2, 3]])->findCycle());
    }
}