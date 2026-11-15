<?php

declare(strict_types=1);

namespace Polypheny;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Org\Polypheny\Prism\ProtoBigDecimal;
use Org\Polypheny\Prism\ProtoBinary;
use Org\Polypheny\Prism\ProtoBoolean;
use Org\Polypheny\Prism\ProtoDate;
use Org\Polypheny\Prism\ProtoDocument;
use Org\Polypheny\Prism\ProtoDouble;
use Org\Polypheny\Prism\ProtoInteger;
use Org\Polypheny\Prism\ProtoInterval;
use Org\Polypheny\Prism\ProtoList;
use Org\Polypheny\Prism\ProtoLong;
use Org\Polypheny\Prism\ProtoNull;
use Org\Polypheny\Prism\ProtoString;
use Org\Polypheny\Prism\ProtoTime;
use Org\Polypheny\Prism\ProtoTimestamp;
use Org\Polypheny\Prism\ProtoValue;
use stdClass;
use UnexpectedValueException;

/**
 * Encodes query parameters and decodes Prism values.
 */
final class ValueCodec
{
    private const MAX_NESTING = 100;

    public static function encode(mixed $value): ProtoValue
    {
        return self::encodeValue($value, 0);
    }

    public static function decode(ProtoValue $value): mixed
    {
        return self::decodeValue($value, 0);
    }

    public static function decodeDocument(ProtoDocument $document): stdClass
    {
        return self::decodeFields($document, 0);
    }

    private static function encodeValue(mixed $value, int $depth): ProtoValue
    {
        if ($depth > self::MAX_NESTING) {
            throw new InvalidArgumentException('Prism parameter nesting exceeds 100 levels.');
        }

        $proto = new ProtoValue();

        return match (true) {
            $value === null => $proto->setNull(new ProtoNull()),
            is_bool($value) => $proto->setBoolean((new ProtoBoolean())->setBoolean($value)),
            is_int($value) && $value >= -2_147_483_648 && $value <= 2_147_483_647 =>
                $proto->setInteger((new ProtoInteger())->setInteger($value)),
            is_int($value) => $proto->setLong((new ProtoLong())->setLong($value)),
            is_float($value) => $proto->setDouble((new ProtoDouble())->setDouble($value)),
            is_string($value) => $proto->setString((new ProtoString())->setString($value)),
            $value instanceof Binary => $proto->setBinary((new ProtoBinary())->setBinary($value->bytes)),
            $value instanceof Decimal => $proto->setBigDecimal(
                (new ProtoBigDecimal())->setScale($value->scale)->setUnscaledValue(self::signedBytes($value->unscaled)),
            ),
            $value instanceof Date => $proto->setDate((new ProtoDate())->setDate($value->daysSinceEpoch)),
            $value instanceof Time => $proto->setTime((new ProtoTime())->setTime($value->millisecondsSinceMidnight)),
            $value instanceof DateTimeInterface => $proto->setTimestamp(
                (new ProtoTimestamp())->setTimestamp(self::encodeTimestamp($value)),
            ),
            $value instanceof Interval => $proto->setInterval(
                (new ProtoInterval())->setMonths($value->months)->setMilliseconds($value->milliseconds),
            ),
            is_array($value) && array_is_list($value) => $proto->setList(self::encodeList($value, $depth)),
            is_array($value), $value instanceof stdClass => $proto->setDocument(self::encodeFields($value, $depth)),
            default => throw new InvalidArgumentException('Unsupported Prism parameter type: ' . get_debug_type($value)),
        };
    }

    private static function decodeValue(ProtoValue $value, int $depth): mixed
    {
        if ($depth > self::MAX_NESTING) {
            throw new UnexpectedValueException('Prism result nesting exceeds 100 levels.');
        }

        return match ($value->getValue()) {
            'null' => null,
            'boolean' => $value->getBoolean()->getBoolean(),
            'integer' => $value->getInteger()->getInteger(),
            'long' => (int) $value->getLong()->getLong(),
            'float' => $value->getFloat()->getFloat(),
            'double' => $value->getDouble()->getDouble(),
            'string' => $value->getString()->getString(),
            'binary' => new Binary($value->getBinary()->getBinary()),
            'file' => new Binary($value->getFile()->getBinary()),
            'big_decimal' => Decimal::fromUnscaled(
                self::signedDigits($value->getBigDecimal()->getUnscaledValue()),
                $value->getBigDecimal()->getScale(),
            ),
            'date' => Date::fromEpochDays((int) $value->getDate()->getDate()),
            'time' => Time::fromMilliseconds($value->getTime()->getTime()),
            'timestamp' => self::decodeTimestamp((int) $value->getTimestamp()->getTimestamp()),
            'interval' => new Interval(
                (int) $value->getInterval()->getMonths(),
                (int) $value->getInterval()->getMilliseconds(),
            ),
            'list' => self::decodeList($value->getList(), $depth),
            'document' => self::decodeFields($value->getDocument(), $depth),
            default => throw new UnexpectedValueException('Prism value has no supported type.'),
        };
    }

    private static function encodeList(array $value, int $depth): ProtoList
    {
        $items = [];
        foreach ($value as $element) {
            $items[] = self::encodeValue($element, $depth + 1);
        }

        return (new ProtoList())->setValues($items);
    }

    /** @return list<mixed> */
    private static function decodeList(ProtoList $list, int $depth): array
    {
        $items = [];
        foreach ($list->getValues() as $element) {
            $items[] = self::decodeValue($element, $depth + 1);
        }

        return $items;
    }

    private static function encodeFields(array|stdClass $value, int $depth): ProtoDocument
    {
        $document = new ProtoDocument();
        $entries = $document->getEntries();
        foreach ($value as $key => $element) {
            $entries[(string) $key] = self::encodeValue($element, $depth + 1);
        }

        return $document;
    }

    private static function decodeFields(ProtoDocument $document, int $depth): stdClass
    {
        $fields = new stdClass();
        foreach ($document->getEntries() as $key => $element) {
            $fields->{(string) $key} = self::decodeValue($element, $depth + 1);
        }

        return $fields;
    }

    private static function encodeTimestamp(DateTimeInterface $value): int
    {
        $seconds = $value->getTimestamp();
        $fraction = (int) $value->format('v');
        $minimumSeconds = intdiv(PHP_INT_MIN, 1000);
        $maximumSeconds = intdiv(PHP_INT_MAX, 1000);

        // The minimum representable millisecond is partway into the previous second.
        $minimumFraction = 1000 + PHP_INT_MIN % 1000;
        if ($seconds === $minimumSeconds - 1 && $fraction >= $minimumFraction) {
            return PHP_INT_MIN + ($fraction - $minimumFraction);
        }
        if ($seconds < $minimumSeconds || $seconds > $maximumSeconds
            || ($seconds === $maximumSeconds && $fraction > PHP_INT_MAX % 1000)) {
            throw new InvalidArgumentException('Timestamp is outside the Prism signed 64-bit millisecond range.');
        }

        return $seconds * 1000 + $fraction;
    }

    private static function decodeTimestamp(int $milliseconds): DateTimeImmutable
    {
        $seconds = intdiv($milliseconds, 1000);
        $fraction = $milliseconds % 1000;
        if ($fraction < 0) {
            --$seconds;
            $fraction += 1000;
        }

        $value = DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', $seconds, $fraction));
        if ($value === false) {
            throw new UnexpectedValueException('Prism timestamp cannot be represented as a DateTimeImmutable.');
        }

        return $value->setTimezone(new DateTimeZone('UTC'));
    }

    private static function signedBytes(string $unscaled): string
    {
        $negative = $unscaled[0] === '-';
        $digits = ltrim($unscaled, '-');
        $bytes = '';

        do {
            $quotient = '';
            $remainder = 0;
            foreach (str_split($digits) as $digit) {
                $value = $remainder * 10 + (int) $digit;
                $quotient .= (string) intdiv($value, 256);
                $remainder = $value % 256;
            }
            $bytes .= chr($remainder);
            $digits = ltrim($quotient, '0');
        } while ($digits !== '');

        $bytes = strrev($bytes);
        if ($negative) {
            $bytes = self::twosComplement($bytes);
            return ord($bytes[0]) < 128 ? "\xff" . $bytes : $bytes;
        }

        return ord($bytes[0]) >= 128 ? "\x00" . $bytes : $bytes;
    }

    private static function signedDigits(string $bytes): string
    {
        $negative = $bytes !== '' && ord($bytes[0]) >= 128;
        if ($negative) {
            $bytes = self::twosComplement($bytes);
        }

        $digits = '0';
        foreach (str_split($bytes) as $byte) {
            $carry = ord($byte);
            for ($i = strlen($digits) - 1; $i >= 0; --$i) {
                $value = (int) $digits[$i] * 256 + $carry;
                $digits[$i] = (string) ($value % 10);
                $carry = intdiv($value, 10);
            }
            if ($carry > 0) {
                $digits = $carry . $digits;
            }
        }

        return ($negative ? '-' : '') . $digits;
    }

    /** Returns the fixed-width two's complement of a big-endian byte string. */
    private static function twosComplement(string $bytes): string
    {
        $carry = 1;
        for ($i = strlen($bytes) - 1; $i >= 0; --$i) {
            $value = 255 - ord($bytes[$i]) + $carry;
            $bytes[$i] = chr($value & 255);
            $carry = $value >> 8;
        }

        return $bytes;
    }
}
