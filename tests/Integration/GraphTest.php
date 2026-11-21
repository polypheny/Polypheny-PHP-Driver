<?php

declare(strict_types=1);

namespace Polypheny\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Polypheny\Connection;
use Polypheny\Cursor;
use Polypheny\DriverException;
use Polypheny\Edge;
use Polypheny\EdgeDirection;
use Polypheny\Node;
use Polypheny\Path;
use Polypheny\ServerException;

final class GraphTest extends TestCase
{
    private ?Connection $connection = null;
    private string $namespace;

    protected function setUp(): void
    {
        if (getenv('POLYPHENY_TEST_INTEGRATION') !== '1') {
            self::markTestSkipped('Set POLYPHENY_TEST_INTEGRATION=1 to test against a live Prism server.');
        }

        $this->connection = self::connect();
        self::assertTrue(
            version_compare($this->connection->serverVersion(), '2.3', '>='),
            'The Graph integration suite requires a server supporting Prism 2.3 graph cells.',
        );
        $this->namespace = 'php_graph_' . bin2hex(random_bytes(6));
        $cursor = $this->connection->cursor();
        $cursor->execute("CREATE GRAPH NAMESPACE $this->namespace");
        $cursor->executeAny(
            'cypher',
            "CREATE (:Person {id: 1, name: 'Alice'})-[:KNOWS {since: 2020}]->"
                . "(:Person {id: 2, name: 'Bob'})-[:KNOWS {since: 2021}]->"
                . "(:Person {id: 3, name: 'Charlie'})",
            namespace: $this->namespace,
        );
        $this->connection->commit();
        $cursor->close();
    }

    protected function tearDown(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            $this->connection->rollback();
            if (isset($this->namespace)) {
                $this->connection->cursor()->execute("DROP NAMESPACE $this->namespace");
            }
        } finally {
            $this->connection->close();
        }
    }

    public function testNodeFramesPageThroughEveryFetchMethod(): void
    {
        $cursor = $this->query('MATCH (n:Person) RETURN n ORDER BY n.id');
        self::assertNull($cursor->description());
        self::assertSame(-1, $cursor->rowCount());
        $alice = $cursor->fetchOne();
        self::assertInstanceOf(Node::class, $alice);
        self::assertNode($alice, 'Alice', 1);
        $cursor->setArraySize(1);
        $middle = $cursor->fetchMany();
        self::assertCount(1, $middle);
        self::assertNode($middle[0], 'Bob', 2);
        $last = iterator_to_array($cursor, false);
        self::assertCount(1, $last);
        self::assertNode($last[0], 'Charlie', 3);
        self::assertNull($cursor->fetchOne());
        self::assertSame([], $cursor->fetchAll());
        self::assertSame([], $cursor->fetchMany(1));
    }

    public function testEdgeFramesRetainEndpointsPropertiesLabelsAndDirection(): void
    {
        $nodes = $this->query('MATCH (n:Person) RETURN n ORDER BY n.id')->fetchAll();
        $edges = $this->query('MATCH ()-[r:KNOWS]->() RETURN r')->fetchAll();
        usort($edges, static fn (Edge $left, Edge $right): int => (int) $left->properties['since'] <=> (int) $right->properties['since']);
        self::assertCount(2, $edges);
        self::assertEdge($edges[0], $nodes[0], $nodes[1], 2020);
        self::assertEdge($edges[1], $nodes[1], $nodes[2], 2021);
    }

    public function testAllGraphProjectionColumnsKeepTheirOrder(): void
    {
        $elements = $this->query("MATCH (a:Person {name: 'Alice'})-[r:KNOWS]->(b:Person) RETURN a, r, b")->fetchAll();
        self::assertCount(3, $elements);
        self::assertNode($elements[0], 'Alice', 1);
        self::assertNode($elements[2], 'Bob', 2);
        self::assertEdge($elements[1], $elements[0], $elements[2], 2020);
    }

    public function testPathInGraphFramesExpandsInTraversalOrder(): void
    {
        $cursor = $this->query("MATCH p=(:Person {name: 'Alice'})-[:KNOWS*1..3]->(:Person {name: 'Charlie'}) RETURN p");
        $elements = $cursor->fetchAll();
        self::assertNull($cursor->description());
        self::assertCount(5, $elements);
        self::assertNode($elements[0], 'Alice', 1);
        self::assertNode($elements[2], 'Bob', 2);
        self::assertNode($elements[4], 'Charlie', 3);
        self::assertEdge($elements[1], $elements[0], $elements[2], 2020);
        self::assertEdge($elements[3], $elements[2], $elements[4], 2021);
    }

    public function testFullGraphScanReturnsNodesAndEdges(): void
    {
        $elements = $this->query('MATCH (*) RETURN *')->fetchAll();
        self::assertCount(5, $elements);
        $nodes = [];
        $edges = [];
        foreach ($elements as $element) {
            if ($element instanceof Node) {
                $nodes[$element->properties['name']] = $element;
            } else {
                self::assertInstanceOf(Edge::class, $element);
                $edges[(int) $element->properties['since']] = $element;
            }
        }
        self::assertEqualsCanonicalizing(['Alice', 'Bob', 'Charlie'], array_keys($nodes));
        self::assertEdge($edges[2020], $nodes['Alice'], $nodes['Bob'], 2020);
        self::assertEdge($edges[2021], $nodes['Bob'], $nodes['Charlie'], 2021);
    }

    public function testMixedNodeAndScalarRowsPreserveBothProjectionOrdersAndMetadata(): void
    {
        foreach ([true, false] as $nodeFirst) {
            $projection = $nodeFirst ? 'n, n.name AS name' : 'n.name AS name, n';
            $nodeColumn = $nodeFirst ? 0 : 1;
            $nameColumn = 1 - $nodeColumn;
            $cursor = $this->query("MATCH (n:Person) RETURN $projection ORDER BY name");
            $description = $cursor->description();
            self::assertNotNull($description);
            self::assertSame($nodeFirst ? ['n', 'name'] : ['name', 'n'], array_column($description, 'name'));
            self::assertSame('NODE', $description[$nodeColumn]['type']);
            foreach (['Alice', 'Bob', 'Charlie'] as $index => $name) {
                $row = $cursor->fetchOne();
                self::assertIsArray($row);
                self::assertCount(2, $row);
                self::assertSame($name, $row[$nameColumn]);
                self::assertNode($row[$nodeColumn], $name, $index + 1);
                self::assertSame($description, $cursor->description());
            }
            self::assertNull($cursor->fetchOne());
        }
    }

    public function testMixedEdgeAndNodeCellsRetainProjectedNames(): void
    {
        $cursor = $this->query("MATCH (a:Person {name: 'Alice'})-[r:KNOWS]->(b:Person) RETURN a, r, b, b.name AS name");
        self::assertSame(['a', 'r', 'b', 'name'], array_column($cursor->description(), 'name'));
        self::assertSame(['NODE', 'EDGE', 'NODE'], array_slice(array_column($cursor->description(), 'type'), 0, 3));
        $rows = $cursor->fetchAll();
        self::assertCount(1, $rows);
        [$alice, $edge, $bob, $name] = $rows[0];
        self::assertNode($alice, 'Alice', 1);
        self::assertNode($bob, 'Bob', 2);
        self::assertEdge($edge, $alice, $bob, 2020);
        self::assertSame('Bob', $name);
    }

    public function testMixedPathRemainsOneTypedOrderedCell(): void
    {
        $cursor = $this->query("MATCH p=(:Person {name: 'Alice'})-[:KNOWS*1..3]->(:Person {name: 'Charlie'}) RETURN p, 'route' AS title");
        self::assertSame(['p', 'title'], array_column($cursor->description(), 'name'));
        self::assertSame('PATH', $cursor->description()[0]['type']);
        $rows = $cursor->fetchAll();
        self::assertCount(1, $rows);
        [$path, $title] = $rows[0];
        self::assertInstanceOf(Path::class, $path);
        self::assertSame('route', $title);
        self::assertCount(3, $path->nodes);
        self::assertCount(2, $path->edges);
        self::assertNode($path->nodes[0], 'Alice', 1);
        self::assertNode($path->nodes[1], 'Bob', 2);
        self::assertNode($path->nodes[2], 'Charlie', 3);
        self::assertEdge($path->edges[0], $path->nodes[0], $path->nodes[1], 2020);
        self::assertEdge($path->edges[1], $path->nodes[1], $path->nodes[2], 2021);
    }

    public function testReversePathKeepsTraversalOrderAndStoredEdgeOrientation(): void
    {
        $cursor = $this->query("MATCH p=(:Person {name: 'Charlie'})<-[:KNOWS*1..3]-(:Person {name: 'Alice'}) RETURN p, 'reverse' AS title");
        self::assertSame(['p', 'title'], array_column($cursor->description(), 'name'));
        $rows = $cursor->fetchAll();
        self::assertCount(1, $rows);
        [$path, $title] = $rows[0];
        self::assertInstanceOf(Path::class, $path);
        self::assertSame('reverse', $title);
        self::assertCount(3, $path->nodes);
        self::assertCount(2, $path->edges);
        self::assertNode($path->nodes[0], 'Charlie', 3);
        self::assertNode($path->nodes[1], 'Bob', 2);
        self::assertNode($path->nodes[2], 'Alice', 1);
        self::assertEdge($path->edges[0], $path->nodes[1], $path->nodes[0], 2021);
        self::assertEdge($path->edges[1], $path->nodes[2], $path->nodes[1], 2020);
    }

    public function testMixedAliasesKeepVariableNamesAndANullScalarCell(): void
    {
        $cursor = $this->query("MATCH (a:Person {name: 'Alice'})-[r:KNOWS]->(b:Person) RETURN a AS source, r AS relationship, b AS target, null AS missing");
        $description = $cursor->description();
        self::assertSame(['source', 'relationship', 'target', 'missing'], array_column($description, 'name'));
        self::assertSame(['NODE', 'EDGE', 'NODE'], array_slice(array_column($description, 'type'), 0, 3));
        self::assertTrue($description[3]['nullable']);
        $rows = $cursor->fetchAll();
        self::assertCount(1, $rows);
        self::assertCount(4, $rows[0]);
        [$alice, $edge, $bob, $missing] = $rows[0];
        self::assertNode($alice, 'Alice', 1);
        self::assertNode($bob, 'Bob', 2);
        self::assertEdge($edge, $alice, $bob, 2020);
        self::assertSame(['a', 'r', 'b'], [$alice->name, $edge->name, $bob->name]);
        self::assertNull($missing);
        self::assertSame($description, $cursor->description());
        self::assertNull($cursor->fetchOne());
    }

    public function testScalarProjectionsUseRelationalRowsAndEmptyResultsKeepTheirContract(): void
    {
        $cursor = $this->query('MATCH (n:Person) RETURN n.name AS name ORDER BY name');
        self::assertSame(['name'], array_column($cursor->description(), 'name'));
        self::assertSame([['Alice'], ['Bob'], ['Charlie']], $cursor->fetchAll());

        $cursor = $this->query("MATCH (n:Person {name: 'Unknown'}) RETURN n");
        self::assertSame([], $cursor->fetchAll());
        self::assertNull($cursor->description());

        $cursor = $this->query("MATCH (n:Person {name: 'Unknown'}) RETURN n, n.name AS name");
        self::assertSame([], $cursor->fetchAll());
        self::assertSame(['n', 'name'], array_column($cursor->description(), 'name'));
        self::assertSame('NODE', $cursor->description()[0]['type']);
    }

    public function testClosingOrReusingPartiallyConsumedAutocommitCursorReleasesTheResult(): void
    {
        $connection = self::connect(autoCommit: true);
        try {
            $cursor = $connection->cursor();
            $cursor->executeAny('cypher', 'MATCH (n:Person) RETURN n ORDER BY n.id', fetchSize: 1, namespace: $this->namespace);
            self::assertNode($cursor->fetchOne(), 'Alice', 1);
            $cursor->close();

            $cursor = $connection->cursor();
            $cursor->executeAny('cypher', 'MATCH (n:Person) RETURN n ORDER BY n.id', fetchSize: 1, namespace: $this->namespace);
            self::assertNode($cursor->fetchOne(), 'Alice', 1);
            $cursor->execute('SELECT 7');
            self::assertSame([7], $cursor->fetchOne());

            $cursor->executeAny('cypher', 'MATCH (n:Person) RETURN n ORDER BY n.id', fetchSize: 1, namespace: $this->namespace);
            self::assertCount(3, $cursor->fetchAll());
            $cursor->execute('SELECT 9');
            self::assertSame([9], $cursor->fetchOne());
        } finally {
            $connection->close();
        }
    }

    public function testOverlappingAutocommitQueryFailurePreservesTheOriginalCursor(): void
    {
        $connection = self::connect(autoCommit: true);
        try {
            $original = $connection->cursor();
            $original->executeAny('cypher', 'MATCH (n:Person) RETURN n ORDER BY n.id', fetchSize: 1, namespace: $this->namespace);
            self::assertNode($original->fetchOne(), 'Alice', 1);
            $overlapping = $connection->cursor();
            try {
                $overlapping->execute('SELECT 1');
                self::fail('A separate autocommit statement must not consume the original Cypher result.');
            } catch (ServerException $error) {
                self::assertStringContainsString('Close or finish the open result', $error->getMessage());
            }
            self::assertSame(['Bob', 'Charlie'], array_map(static fn (Node $node): string => $node->properties['name'], $original->fetchAll()));
            self::assertSame([1], $overlapping->execute('SELECT 1')->fetchOne());
        } finally {
            $connection->close();
        }
    }

    public function testRolledBackGraphFetchDiscardsFailedStateAndAllowsReexecution(): void
    {
        $query = 'MATCH (n:Person) RETURN n ORDER BY n.id';
        $cursor = $this->query($query);
        self::assertNode($cursor->fetchOne(), 'Alice', 1);
        $this->connection->rollback();

        try {
            $cursor->fetchOne();
            self::fail('Fetching after rollback must report that the server result is closed.');
        } catch (ServerException $error) {
            self::assertStringContainsString('No open Cypher result exists for this statement', $error->getMessage());
        }
        try {
            $cursor->fetchOne();
            self::fail('A failed fetch must clear the local result state.');
        } catch (DriverException $error) {
            self::assertSame('There is no result set to fetch.', $error->getMessage());
        }

        $cursor->executeAny('cypher', $query, fetchSize: 1, namespace: $this->namespace);
        self::assertSame(['Alice', 'Bob', 'Charlie'], array_map(
            static fn (Node $node): string => $node->properties['name'],
            $cursor->fetchAll(),
        ));
    }

    public function testGraphEntityMetadataAndSingleStatementErrorsLeaveTheConnectionUsable(): void
    {
        $response = $this->connection->searchEntities($this->namespace, 'php_graph_%');
        self::assertCount(1, $response->getEntities());
        self::assertNotNull($response->getEntities()[0]->getGraph());
        self::assertNull($response->getEntities()[0]->getDocument());
        self::assertCount(0, $this->connection->searchEntities($this->namespace, 'missing%')->getEntities());

        try {
            $this->query('MATCH (n:Person) RETURN n; MATCH (n:Person) RETURN n');
            self::fail('Multiple Cypher statements must report a server error.');
        } catch (ServerException $error) {
            self::assertStringContainsString('exactly one Cypher statement', $error->getMessage());
        }
        $this->connection->rollback();
        self::assertCount(3, $this->query('MATCH (n:Person) RETURN n')->fetchAll());
    }

    private function query(string $query): Cursor
    {
        return $this->connection->cursor()->executeAny('cypher', $query, fetchSize: 1, namespace: $this->namespace);
    }

    private static function assertNode(Node $node, string $name, int $id): void
    {
        self::assertNotSame('', $node->id);
        self::assertSame(['Person'], $node->labels);
        self::assertSame($name, $node->properties['name']);
        self::assertSame($id, (int) $node->properties['id']);
    }

    private static function assertEdge(Edge $edge, Node $left, Node $right, int $since): void
    {
        self::assertNotSame('', $edge->id);
        self::assertSame(['KNOWS'], $edge->labels);
        self::assertSame($since, (int) $edge->properties['since']);
        self::assertSame($left->id, $edge->left);
        self::assertSame($right->id, $edge->right);
        self::assertSame(EdgeDirection::LEFT_TO_RIGHT, $edge->direction);
    }

    private static function connect(bool $autoCommit = false): Connection
    {
        return Connection::connectTcp(
            getenv('POLYPHENY_TEST_HOST') ?: '127.0.0.1',
            (int) (getenv('POLYPHENY_TEST_PORT') ?: '20590'),
            getenv('POLYPHENY_TEST_USER') ?: 'pa',
            getenv('POLYPHENY_TEST_PASSWORD') ?: '',
            $autoCommit,
        );
    }
}
