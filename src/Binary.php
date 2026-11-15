<?php

declare(strict_types=1);

namespace Polypheny;

/**
 * Binary data. Use this instead of a PHP string to send a binary parameter.
 */
final readonly class Binary
{
    public function __construct(public string $bytes)
    {
    }
}
