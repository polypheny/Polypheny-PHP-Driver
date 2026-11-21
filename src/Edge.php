<?php

declare(strict_types=1);

namespace Polypheny;

use InvalidArgumentException;

/** An edge with endpoints expressed as node IDs. */
final readonly class Edge
{
    /**
     * @param array<string, mixed> $properties
     * @param list<string> $labels
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $properties = [],
        public array $labels = [],
        public string $left = '',
        public string $right = '',
        public EdgeDirection $direction = EdgeDirection::UNSPECIFIED,
    ) {
        if (!array_is_list($labels)) {
            throw new InvalidArgumentException('Edge labels must be a list of strings.');
        }
        foreach ($labels as $label) {
            if (!is_string($label)) {
                throw new InvalidArgumentException('Edge labels must be a list of strings.');
            }
        }
    }
}
