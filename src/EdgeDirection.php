<?php

declare(strict_types=1);

namespace Polypheny;

/** The orientation of an edge relative to its left and right endpoints. */
enum EdgeDirection: int
{
    case UNSPECIFIED = 0;
    case LEFT_TO_RIGHT = 1;
    case RIGHT_TO_LEFT = 2;
    case NONE = 3;
}
