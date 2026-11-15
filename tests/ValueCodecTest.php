<?php

declare(strict_types=1);

namespace Polypheny\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Org\Polypheny\Prism\ProtoFile;
use Org\Polypheny\Prism\ProtoFloat;
use Org\Polypheny\Prism\ProtoTimestamp;
use Org\Polypheny\Prism\ProtoValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polypheny\Binary;
use Polypheny\Date;
use Polypheny\Decimal;
use Polypheny\Interval;
use Polypheny\Time;
use Polypheny\ValueCodec;
use stdClass;
use UnexpectedValueException;

final class ValueCodecTest extends TestCase
{
    #[DataProvider('nativeValues')]
    public function testNativeValuesKeepTheirTypesOnTheWire(mixed $input, string $wireType): void
    {
        $value = self::wireRoundTrip(ValueCodec::encode($input));

        self::assertSame($wireType, $value->getValue());
        self::assertSame($input, ValueCodec::decode($value));
    }

    public static function nativeValues(): iterable
    {
        yield [null, 'null'];
        yield [false, 'boolean'];
        yield [true, 'boolean'];
        yield [-2_147_483_648, 'integer'];
        yield [2_147_483_647, 'integer'];
        yield [-2_147_483_649, 'long'];
        yield [2_147_483_648, 'long'];
        yield [PHP_INT_MIN, 'long'];
        yield [PHP_INT_MAX, 'long'];
        yield [-0.125, 'double'];
        yield ['', 'string'];
        yield ['Grüße 🌍', 'string'];
        yield [[], 'list'];
        yield [[1, [null, 'hello'], false], 'list'];
    }

    #[DataProvider('decimals')]
    public function testDecimalEncodingMatchesSignedBigEndianWireBytes(
        string $input,
        string $wireHex,
        int $scale,
        string $canonical,
    ): void {
        $value = self::wireRoundTrip(ValueCodec::encode(new Decimal($input)));

        self::assertSame('big_decimal', $value->getValue());
        self::assertSame($wireHex, bin2hex($value->getBigDecimal()->getUnscaledValue()));
        self::assertSame($scale, $value->getBigDecimal()->getScale());
        self::assertSame($canonical, (string) ValueCodec::decode($value));
    }

    public static function decimals(): iterable
    {
        yield ['0.00', '00', 2, '0.00'];
        yield ['127', '7f', 0, '127'];
        yield ['128', '0080', 0, '128'];
        yield ['-128', '80', 0, '-128'];
        yield ['-129', 'ff7f', 0, '-129'];
        yield ['-32768', '8000', 0, '-32768'];
        yield ['-32769', 'ff7fff', 0, '-32769'];
        yield ['18446744073709551616', '010000000000000000', 0, '18446744073709551616'];
        yield ['-18446744073709551616', 'ff0000000000000000', 0, '-18446744073709551616'];
        yield ['123.4500', '12d644', 4, '123.4500'];
        yield ['-.005E-4', 'fb', 7, '-5E-7'];
        yield ['1.23E+8', '7b', -6, '1.23E+8'];
        yield ['1E+2147483648', '01', -2_147_483_648, '1E+2147483648'];
        yield ['1E-2147483647', '01', 2_147_483_647, '1E-2147483647'];
    }

    public function testBinaryAndFileValuesPreserveAllBytes(): void
    {
        $bytes = "\x00\x01\xff\x80\x00";
        $binary = self::wireRoundTrip(ValueCodec::encode(new Binary($bytes)));
        $file = self::wireRoundTrip((new ProtoValue())->setFile((new ProtoFile())->setBinary($bytes)));

        self::assertSame('binary', $binary->getValue());
        self::assertSame($bytes, ValueCodec::decode($binary)->bytes);
        self::assertSame($bytes, ValueCodec::decode($file)->bytes);
    }

    public function testSinglePrecisionFloatsAreDecoded(): void
    {
        $value = self::wireRoundTrip((new ProtoValue())->setFloat((new ProtoFloat())->setFloat(1.25)));

        self::assertSame(1.25, ValueCodec::decode($value));
    }

    public function testDateTimeAndIntervalRoundTrip(): void
    {
        $date = self::wireRoundTrip(ValueCodec::encode(new Date('1969-12-31')));
        $time = self::wireRoundTrip(ValueCodec::encode(new Time('23:59:59.999')));
        $timestamp = self::wireRoundTrip(ValueCodec::encode(new DateTimeImmutable('1970-01-01T01:59:59.999+02:00')));
        $interval = self::wireRoundTrip(ValueCodec::encode(new Interval(43, -187_000)));

        self::assertSame(-1, $date->getDate()->getDate());
        self::assertSame('1969-12-31', (string) ValueCodec::decode($date));
        self::assertSame(86_399_999, $time->getTime()->getTime());
        self::assertSame('23:59:59.999', (string) ValueCodec::decode($time));
        self::assertSame(-1, $timestamp->getTimestamp()->getTimestamp());
        self::assertEquals(
            new DateTimeImmutable('1969-12-31T23:59:59.999', new DateTimeZone('UTC')),
            ValueCodec::decode($timestamp),
        );
        self::assertSame('UTC', ValueCodec::decode($timestamp)->getTimezone()->getName());
        self::assertEquals(new Interval(43, -187_000), ValueCodec::decode($interval));
    }

    public function testTimestampBoundariesDoNotLosePrecision(): void
    {
        foreach ([PHP_INT_MIN, PHP_INT_MAX] as $bound) {
            $value = self::wireRoundTrip((new ProtoValue())->setTimestamp((new ProtoTimestamp())->setTimestamp($bound)));
            self::assertSame($bound, ValueCodec::encode(ValueCodec::decode($value))->getTimestamp()->getTimestamp());
        }
    }

    public function testDocumentsPreserveEmptyObjectsAndNumericKeys(): void
    {
        $value = self::wireRoundTrip(ValueCodec::encode([
            'nested' => ['first' => true, 'second' => null],
            'array' => [],
            'object' => new stdClass(),
            'numeric' => (object) ['first', 'second'],
        ]));
        $document = ValueCodec::decodeDocument($value->getDocument());

        self::assertEquals($document, ValueCodec::decode($value));
        self::assertSame(
            '{"nested":{"first":true,"second":null},"array":[],"object":{},"numeric":{"0":"first","1":"second"}}',
            json_encode($document, JSON_THROW_ON_ERROR),
        );
    }

    public function testUnsupportedParameterTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ValueCodec::encode(new \ArrayObject());
    }

    public function testMissingValueTypeIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        ValueCodec::decode(new ProtoValue());
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Date('2025-02-29');
    }

    public function testInvalidTimeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Time('24:00:00');
    }

    private static function wireRoundTrip(ProtoValue $value): ProtoValue
    {
        $result = new ProtoValue();
        $result->mergeFromString($value->serializeToString());

        return $result;
    }
}
