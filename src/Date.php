<?php

declare(strict_types=1);

namespace Polypheny;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;

/**
 * A calendar date without a time or timezone.
 */
final readonly class Date implements Stringable
{
    public int $daysSinceEpoch;

    public function __construct(private string $value)
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1 || substr($value, 0, 4) === '0000') {
            throw new InvalidArgumentException('Expected a date in YYYY-MM-DD format.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }

        $this->daysSinceEpoch = intdiv($date->getTimestamp(), 86_400);
    }

    public static function fromEpochDays(int $days): self
    {
        // 0001-01-01 and 9999-12-31 bound the four-digit calendar representation.
        if ($days < -719_162 || $days > 2_932_896) {
            throw new InvalidArgumentException('Date is outside the supported calendar range.');
        }

        return new self((new DateTimeImmutable('@' . ($days * 86_400)))->format('Y-m-d'));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
