<?php

declare(strict_types=1);

namespace Polypheny;

use InvalidArgumentException;
use Stringable;

/**
 * A time of day without a date or timezone. Prism preserves milliseconds.
 */
final readonly class Time implements Stringable
{
    public int $millisecondsSinceMidnight;

    public function __construct(string $value)
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.(\d{1,3}))?$/D', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Expected a time in HH:MM:SS or HH:MM:SS.mmm format.');
        }

        $seconds = ((int) $parts[1] * 60 + (int) $parts[2]) * 60 + (int) $parts[3];
        $this->millisecondsSinceMidnight = $seconds * 1000 + (int) str_pad($parts[4] ?? '', 3, '0');
    }

    public static function fromMilliseconds(int $milliseconds): self
    {
        return new self(self::formatMilliseconds($milliseconds));
    }

    public function __toString(): string
    {
        return self::formatMilliseconds($this->millisecondsSinceMidnight);
    }

    private static function formatMilliseconds(int $milliseconds): string
    {
        if ($milliseconds < 0 || $milliseconds >= 86_400_000) {
            throw new InvalidArgumentException('Time is outside the range of a single day.');
        }

        $seconds = intdiv($milliseconds, 1000);
        $fraction = $milliseconds % 1000;
        $value = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);

        return $fraction === 0 ? $value : $value . sprintf('.%03d', $fraction);
    }
}
