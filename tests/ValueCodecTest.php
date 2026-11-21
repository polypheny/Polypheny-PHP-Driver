<?php

declare(strict_types=1);

namespace Polypheny\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Org\Polypheny\Prism\GraphElement;
use Org\Polypheny\Prism\ProtoEdge;
use Org\Polypheny\Prism\ProtoFile;
use Org\Polypheny\Prism\ProtoFloat;
use Org\Polypheny\Prism\ProtoGraph;
use Org\Polypheny\Prism\ProtoNode;
use Org\Polypheny\Prism\ProtoPath;
use Org\Polypheny\Prism\ProtoTimestamp;
use Org\Polypheny\Prism\ProtoValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polypheny\Binary;
use Polypheny\Date;
use Polypheny\Decimal;
use Polypheny\Edge;
use Polypheny\EdgeDirection;
use Polypheny\Graph;
use Polypheny\Interval;
use Polypheny\Node;
use Polypheny\Path;
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

    public function testNodesPreserveIdentityLabelsAndTypedProperties(): void
    {
        $node = new Node(
            id: 'node-α',
            name: 'person',
            properties: [
                'name' => 'Grüße 🌍',
                'enabled' => true,
                'missing' => null,
                'count' => PHP_INT_MAX,
                'amount' => new Decimal('19.90'),
                'day' => new Date('1969-12-31'),
                'time' => new Time('23:59:59.999'),
                'timestamp' => new DateTimeImmutable('1970-01-01T00:00:00.123Z'),
                'period' => new Interval(-3, 500),
                'bytes' => new Binary("\x00\xff"),
                'values' => [1, null, (object) ['active' => false]],
                'empty' => new stdClass(),
                '0' => 'numeric key',
            ],
            labels: ['Person', 'Überprüfung'],
        );
        $value = self::wireRoundTrip(ValueCodec::encode($node));

        self::assertSame('node', $value->getValue());
        self::assertSame($node->id, $value->getNode()->getId());
        self::assertSame($node->name, $value->getNode()->getName());
        self::assertSame($node->labels, iterator_to_array($value->getNode()->getLabels(), false));
        self::assertSame('big_decimal', $value->getNode()->getProperties()['amount']->getValue());
        self::assertEquals($node, ValueCodec::decode($value));
        self::assertInstanceOf(stdClass::class, ValueCodec::decode($value)->properties['empty']);
    }

    #[DataProvider('edgeDirections')]
    public function testEdgesPreserveEndpointsAndDirections(EdgeDirection $direction, int $wireDirection): void
    {
        $edge = new Edge(
            id: 'relationship-1',
            name: 'relationship',
            properties: ['weight' => 0.5, 'details' => (object) ['known' => true]],
            labels: ['KNOWS'],
            left: 'node-1',
            right: 'node-2',
            direction: $direction,
        );
        $value = self::wireRoundTrip(ValueCodec::encode($edge));

        self::assertSame('edge', $value->getValue());
        self::assertSame('relationship-1', $value->getEdge()->getId());
        self::assertSame('relationship', $value->getEdge()->getName());
        self::assertSame('node-1', $value->getEdge()->getLeft());
        self::assertSame('node-2', $value->getEdge()->getRight());
        self::assertSame(['KNOWS'], iterator_to_array($value->getEdge()->getLabels(), false));
        self::assertSame($wireDirection, $value->getEdge()->getDirection());
        self::assertEquals($edge, ValueCodec::decode($value));
    }

    public static function edgeDirections(): iterable
    {
        yield [EdgeDirection::UNSPECIFIED, 0];
        yield [EdgeDirection::LEFT_TO_RIGHT, 1];
        yield [EdgeDirection::RIGHT_TO_LEFT, 2];
        yield [EdgeDirection::NONE, 3];
    }

    public function testPathsPreserveReverseTraversalAndRepeatedNodes(): void
    {
        $first = new Node(id: 'first', labels: ['Point']);
        $second = new Node(id: 'second', properties: ['position' => 2]);
        $edge = new Edge(id: 'link', left: 'first', right: 'second', direction: EdgeDirection::LEFT_TO_RIGHT);
        $path = new Path([$second, $first, $second], [$edge, $edge]);
        $value = self::wireRoundTrip(ValueCodec::encode($path));

        self::assertSame('path', $value->getValue());
        self::assertSame('second', $value->getPath()->getNodes()[0]->getId());
        self::assertSame('first', $value->getPath()->getEdges()[0]->getLeft());
        self::assertSame(1, $value->getPath()->getEdges()[0]->getDirection());
        self::assertEquals($path, ValueCodec::decode($value));
        self::assertEquals(new Path([$first]), ValueCodec::decode(self::wireRoundTrip(ValueCodec::encode(new Path([$first])))));
    }

    public function testGraphsPreserveIsolatedNodesAndEmptyCollections(): void
    {
        $first = new Node(id: 'first');
        $second = new Node(id: 'second');
        $isolated = new Node(id: 'isolated', properties: ['standalone' => true]);
        $edge = new Edge(id: 'link', left: 'first', right: 'second', direction: EdgeDirection::NONE);
        $graph = new Graph([$isolated, $second, $first], [$edge]);
        $value = self::wireRoundTrip(ValueCodec::encode($graph));

        self::assertSame('graph', $value->getValue());
        self::assertCount(3, $value->getGraph()->getNodes());
        self::assertSame('isolated', $value->getGraph()->getNodes()[0]->getId());
        self::assertCount(1, $value->getGraph()->getEdges());
        self::assertEquals($graph, ValueCodec::decode($value));
        self::assertEquals(new Graph(), ValueCodec::decode(self::wireRoundTrip(ValueCodec::encode(new Graph()))));
        self::assertEquals(new Node(), ValueCodec::decode(self::wireRoundTrip(ValueCodec::encode(new Node()))));
        self::assertEquals(new Edge(), ValueCodec::decode(self::wireRoundTrip(ValueCodec::encode(new Edge()))));
    }

    public function testGraphValuesCanBeNestedInListsDocumentsAndProperties(): void
    {
        $node = new Node(id: 'node');
        $edge = new Edge(id: 'self', left: 'node', right: 'node', direction: EdgeDirection::RIGHT_TO_LEFT);
        $path = new Path([$node, $node], [$edge]);
        $graph = new Graph([$node], [$edge]);
        $container = new Node(id: 'container', properties: ['graph' => $graph, 'path' => $path]);
        $input = [$node, $edge, $path, $graph, (object) ['value' => $container]];
        $value = self::wireRoundTrip(ValueCodec::encode($input));

        self::assertEquals($input, ValueCodec::decode($value));
    }

    public function testLegacyGraphElementsUseTheSameNodeAndEdgeRepresentations(): void
    {
        $node = new Node(id: 'node', properties: ['empty' => new stdClass()], labels: ['Point']);
        $edge = new Edge(id: 'edge', left: 'node', right: 'node', direction: EdgeDirection::NONE);
        $nodeElement = (new GraphElement())->setNode(ValueCodec::encode($node)->getNode());
        $edgeElement = (new GraphElement())->setEdge(ValueCodec::encode($edge)->getEdge());

        self::assertEquals($node, ValueCodec::decodeGraphElement($nodeElement));
        self::assertEquals($edge, ValueCodec::decodeGraphElement($edgeElement));
    }

    public function testEmptyGraphElementIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        ValueCodec::decodeGraphElement(new GraphElement());
    }

    public function testUnknownEdgeDirectionIsRejectedInCellsAndLegacyElements(): void
    {
        $edge = (new ProtoEdge())->setDirection(99);
        try {
            ValueCodec::decode(self::wireRoundTrip((new ProtoValue())->setEdge($edge)));
            self::fail('An unknown edge direction must be rejected.');
        } catch (UnexpectedValueException $error) {
            self::assertStringContainsString('99', $error->getMessage());
        }

        $this->expectException(UnexpectedValueException::class);
        ValueCodec::decodeGraphElement((new GraphElement())->setEdge($edge));
    }

    #[DataProvider('invalidGraphValues')]
    public function testInvalidGraphValueCollectionsAreRejected(callable $construct): void
    {
        $this->expectException(InvalidArgumentException::class);
        $construct();
    }

    public static function invalidGraphValues(): iterable
    {
        yield 'node label type' => [static fn () => new Node(labels: [1])];
        yield 'node label keys' => [static fn () => new Node(labels: ['label' => 'Point'])];
        yield 'edge label type' => [static fn () => new Edge(labels: [false])];
        yield 'edge label keys' => [static fn () => new Edge(labels: [1 => 'Link'])];
        yield 'graph node keys' => [static fn () => new Graph(['node' => new Node()])];
        yield 'graph edge keys' => [static fn () => new Graph([], ['edge' => new Edge()])];
        yield 'graph node type' => [static fn () => new Graph([new Edge()])];
        yield 'graph edge type' => [static fn () => new Graph([], [new Node()])];
        yield 'empty path' => [static fn () => new Path([])];
        yield 'path node keys' => [static fn () => new Path(['node' => new Node()])];
        yield 'path edge keys' => [static fn () => new Path([new Node(), new Node()], ['edge' => new Edge()])];
        yield 'path node type' => [static fn () => new Path([new Edge()])];
        yield 'path edge type' => [static fn () => new Path([new Node(), new Node()], [new Node()])];
        yield 'path counts' => [static fn () => new Path([new Node(), new Node()])];
        yield 'path endpoints' => [static fn () => new Path([new Node(id: 'a'), new Node(id: 'b')], [new Edge(left: 'a', right: 'c')])];
    }

    #[DataProvider('invalidWirePaths')]
    public function testMalformedWirePathsAreRejected(ProtoPath $path): void
    {
        $this->expectException(UnexpectedValueException::class);
        ValueCodec::decode(self::wireRoundTrip((new ProtoValue())->setPath($path)));
    }

    public static function invalidWirePaths(): iterable
    {
        yield 'empty path' => [new ProtoPath()];
        yield 'disconnected endpoints' => [(new ProtoPath())
            ->setNodes([(new ProtoNode())->setId('a'), (new ProtoNode())->setId('b')])
            ->setEdges([(new ProtoEdge())->setLeft('a')->setRight('c')])];
    }

    public function testGraphNestingLimitsIncludeNodeAndEdgeProperties(): void
    {
        $value = new Node(id: 'leaf');
        for ($depth = 0; $depth < 100; ++$depth) {
            $value = new Node(properties: ['next' => $value]);
        }
        self::assertEquals($value, ValueCodec::decode(ValueCodec::encode($value)));

        $this->expectException(InvalidArgumentException::class);
        ValueCodec::encode(new Edge(properties: ['next' => $value]));
    }

    public function testOverlyNestedWireGraphValuesAreRejected(): void
    {
        $value = (new ProtoValue())->setEdge(new ProtoEdge());
        for ($depth = 0; $depth < 101; ++$depth) {
            $value = (new ProtoValue())->setNode((new ProtoNode())->setProperties(['next' => $value]));
        }

        $this->expectException(UnexpectedValueException::class);
        ValueCodec::decode($value);
    }

    public function testGraphContainersCannotBypassTheNestingLimitWithEmptyElements(): void
    {
        $value = new Graph([new Node()], [new Edge()]);
        for ($depth = 0; $depth < 100; ++$depth) {
            $value = ['nested' => $value];
        }

        $this->expectException(InvalidArgumentException::class);
        ValueCodec::encode($value);
    }

    public function testWireGraphContainersCannotBypassTheNestingLimit(): void
    {
        $value = (new ProtoValue())->setGraph((new ProtoGraph())->setNodes([new ProtoNode()]));
        for ($depth = 0; $depth < 100; ++$depth) {
            $value = (new ProtoValue())->setNode((new ProtoNode())->setProperties(['next' => $value]));
        }

        $this->expectException(UnexpectedValueException::class);
        ValueCodec::decode($value);
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
