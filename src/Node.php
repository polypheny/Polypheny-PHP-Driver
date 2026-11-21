<?php

declare(strict_types=1);

namespace Polypheny;

use InvalidArgumentException;

/** A node in a labeled property graph. */
final readonly class Node
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
    ) {
        if (!array_is_list($labels)) {
            throw new InvalidArgumentException('Node labels must be a list of strings.');
        }
        foreach ($labels as $label) {
            if (!is_string($label)) {
                throw new InvalidArgumentException('Node labels must be a list of strings.');
            }
        }
    }
}
