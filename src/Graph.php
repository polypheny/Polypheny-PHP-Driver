<?php

declare(strict_types=1);

namespace Polypheny;

use InvalidArgumentException;

/** A graph containing nodes and edges, including isolated nodes. */
final readonly class Graph
{
    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     */
    public function __construct(public array $nodes = [], public array $edges = [])
    {
        if (!array_is_list($nodes) || !array_is_list($edges)) {
            throw new InvalidArgumentException('Graph nodes and edges must be lists.');
        }
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                throw new InvalidArgumentException('Graph nodes must contain only Node values.');
            }
        }
        foreach ($edges as $edge) {
            if (!$edge instanceof Edge) {
                throw new InvalidArgumentException('Graph edges must contain only Edge values.');
            }
        }
    }
}
