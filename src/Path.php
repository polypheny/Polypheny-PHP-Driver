<?php

declare(strict_types=1);

namespace Polypheny;

use InvalidArgumentException;

/** An ordered traversal whose edges retain their orientation in the graph. */
final readonly class Path
{
    /**
     * @param non-empty-list<Node> $nodes
     * @param list<Edge> $edges
     */
    public function __construct(public array $nodes, public array $edges = [])
    {
        if (!array_is_list($nodes) || !array_is_list($edges) || count($nodes) !== count($edges) + 1) {
            throw new InvalidArgumentException('A path must have a list of nodes with one more node than edges.');
        }
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                throw new InvalidArgumentException('Path nodes must contain only Node values.');
            }
        }
        foreach ($edges as $index => $edge) {
            if (!$edge instanceof Edge) {
                throw new InvalidArgumentException('Path edges must contain only Edge values.');
            }
            $start = $nodes[$index]->id;
            $end = $nodes[$index + 1]->id;
            if (!(($edge->left === $start && $edge->right === $end)
                || ($edge->left === $end && $edge->right === $start))) {
                throw new InvalidArgumentException('Each path edge must connect its adjacent nodes.');
            }
        }
    }
}
