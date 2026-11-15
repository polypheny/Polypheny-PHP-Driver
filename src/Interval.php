<?php

declare(strict_types=1);

namespace Polypheny;

/**
 * A Prism interval with separate calendar months and elapsed milliseconds.
 */
final readonly class Interval
{
    public function __construct(public int $months, public int $milliseconds)
    {
    }
}
