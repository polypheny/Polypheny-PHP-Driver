<?php

declare(strict_types=1);

namespace Polypheny\Tests\Integration;

use DateTimeImmutable;
use Org\Polypheny\Prism\Document;
use PHPUnit\Framework\TestCase;
use Polypheny\Binary;
use Polypheny\Connection;
use Polypheny\Date;
use Polypheny\Decimal;
use Polypheny\DriverException;
use Polypheny\Interval;
use Polypheny\ServerException;
use Polypheny\Time;
use stdClass;

final class ConnectorTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        if (getenv('POLYPHENY_TEST_INTEGRATION') !== '1') {
            self::markTestSkipped('Set POLYPHENY_TEST_INTEGRATION=1 to test against a live Prism server.');
        }

        $this->connection = self::connectTcp();
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testIndexedAndNamedParametersWithTransactions(): void
    {
        $table = self::temporaryName('php_rows');
        $cursor = $this->connection->cursor();
        $cursor->execute("CREATE TABLE $table (id INTEGER NOT NULL, label VARCHAR(80), amount BIGINT, PRIMARY KEY (id))");

        try {
            $cursor->executeMany("INSERT INTO $table (id, label, amount) VALUES (?, ?, ?)", [
                [1, 'Mária', PHP_INT_MIN],
                [2, 'Daniel', null],
                [3, 'Peter', PHP_INT_MAX],
            ]);
            $this->connection->commit();

            $cursor->execute("UPDATE $table SET label = :label WHERE id = :id", ['id' => 2, 'label' => 'Alice']);
            self::assertSame(1, $cursor->rowCount());
            self::assertNull($cursor->description());
            $this->connection->rollback();

            $cursor->execute("SELECT label, amount FROM $table WHERE id = :id OR id = :id", ['id' => 2]);
            self::assertSame(-1, $cursor->rowCount());
            $description = $cursor->description();
            self::assertNotNull($description);
            self::assertSame(['label', 'amount'], array_map('strtolower', array_column($description, 'name')));
            self::assertTrue($description[1]['nullable']);
            self::assertSame(['Daniel', null], $cursor->fetchOne());
            self::assertNull($cursor->fetchOne());

            $cursor->execute("SELECT id, label, amount FROM $table ORDER BY id");
            self::assertSame([
                [1, 'Mária', PHP_INT_MIN],
                [2, 'Daniel', null],
                [3, 'Peter', PHP_INT_MAX],
            ], $cursor->fetchAll());
        } finally {
            $this->connection->rollback();
            $cursor->execute("DROP TABLE $table");
            $cursor->close();
        }
    }

    public function testSqlFetchMethodsAndIterationSpanFrames(): void
    {
        $table = self::temporaryName('php_frames');
        $cursor = $this->connection->cursor();
        $cursor->execute("CREATE TABLE $table (id INTEGER NOT NULL, PRIMARY KEY (id))");

        try {
            $expected = array_map(static fn (int $id): array => [$id], range(1, 12));
            $cursor->executeMany("INSERT INTO $table (id) VALUES (?)", $expected);
            $this->connection->commit();

            $cursor->execute("SELECT id FROM $table ORDER BY id", fetchSize: 2);
            self::assertSame([1], $cursor->fetchOne());
            $cursor->setArraySize(3);
            self::assertSame(3, $cursor->arraySize());
            self::assertSame([[2], [3], [4]], $cursor->fetchMany());
            self::assertSame(array_slice($expected, 4), iterator_to_array($cursor, false));
            self::assertSame([], $cursor->fetchAll());

            $cursor->execute("SELECT id FROM $table ORDER BY id", fetchSize: 1);
            self::assertSame($expected, $cursor->fetchAll());

            foreach ([0, -1] as $fetchSize) {
                $cursor->execute("SELECT id FROM $table ORDER BY id", fetchSize: $fetchSize);
                self::assertSame($expected, $cursor->fetchAll());
            }

            $cursor->execute("SELECT id FROM $table WHERE id = -1", fetchSize: 1);
            self::assertNull($cursor->fetchOne());
            self::assertSame([], $cursor->fetchMany(2));
        } finally {
            $this->connection->rollback();
            $cursor->execute("DROP TABLE $table");
            $cursor->close();
        }
    }

    public function testClosingConnectionsPersistsOnlyCommittedWrites(): void
    {
        $table = self::temporaryName('php_autocommit');
        $cursor = $this->connection->cursor();
        $cursor->execute("CREATE TABLE $table (id INTEGER NOT NULL, PRIMARY KEY (id))");

        try {
            $writer = self::connectTcp(autoCommit: true);
            try {
                $writer->cursor()->execute("INSERT INTO $table (id) VALUES (?)", [42]);
            } finally {
                $writer->close();
            }

            $uncommittedWriter = self::connectTcp();
            try {
                $uncommittedWriter->cursor()->execute("INSERT INTO $table (id) VALUES (?)", [43]);
            } finally {
                $uncommittedWriter->close();
            }

            $cursor->execute("SELECT id FROM $table ORDER BY id");
            self::assertSame([[42]], $cursor->fetchAll());
        } finally {
            $this->connection->rollback();
            $cursor->execute("DROP TABLE $table");
            $cursor->close();
        }
    }

    public function testMqlFetchesDocumentFramesInTheRequestedNamespace(): void
    {
        $namespace = self::temporaryName('php_documents');
        $cursor = $this->connection->cursor();
        $cursor->execute("CREATE DOCUMENT NAMESPACE $namespace");

        try {
            $cursor->executeAny('mongo', 'db.createCollection("events")', namespace: $namespace);
            $documents = [
                ['_id' => 'first', 'tags' => ['arrival', 'php'], 'details' => ['count' => 1], 'active' => true],
                ['_id' => 'second', 'tags' => ['complete'], 'details' => ['count' => 2], 'active' => false],
                ['_id' => 'third', 'tags' => [], 'details' => new stdClass(), 'active' => null],
            ];
            foreach ($documents as $document) {
                $cursor->executeAny(
                    'mongo',
                    'db.events.insertOne(' . json_encode($document, JSON_THROW_ON_ERROR) . ')',
                    namespace: $namespace,
                );
            }
            $this->connection->commit();

            $cursor->executeAny('mongo', 'db.events.find({})', fetchSize: 1, namespace: $namespace);
            $actual = $cursor->fetchAll();
            self::assertContainsOnlyInstancesOf(stdClass::class, $actual);
            usort($actual, static fn (stdClass $left, stdClass $right): int => $left->_id <=> $right->_id);
            self::assertEquals(json_decode(json_encode($documents, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR), $actual);
            self::assertSame([true, false, null], array_column($actual, 'active'));
            self::assertInstanceOf(stdClass::class, $actual[2]->details);
            self::assertNull($cursor->description());
        } finally {
            $this->connection->rollback();
            $cursor->execute("DROP NAMESPACE $namespace");
            $cursor->close();
        }
    }

    public function testSqlTypedParametersAndResults(): void
    {
        $cursor = $this->connection->cursor();
        $sql = <<<'SQL'
            SELECT CAST(? AS DECIMAL(16, 4)),
                   CAST(? AS DECIMAL(30, 0)),
                   CAST(? AS VARBINARY(6)),
                   CAST(? AS DATE),
                   CAST(? AS TIME(3)),
                   CAST(? AS TIMESTAMP(3)),
                   CAST(? AS JSON),
                   CAST(? AS INTEGER ARRAY(1, 3)),
                   CAST(? AS DOUBLE)
            SQL;

        $decimal = new Decimal('-1234567890.4200');
        $wideDecimal = new Decimal('151115727451828646838272');
        $binary = new Binary("\x00\x01\xffABC");
        $date = new Date('1969-12-31');
        $time = new Time('15:19:10.123');
        $timestamp = new DateTimeImmutable('1970-01-01T01:59:59.999+02:00');
        $document = ['source' => 'php', 'empty' => new stdClass(), 'list' => [42, null, false]];
        $list = [1, 2, 3];
        $row = $cursor->execute($sql, [
            $decimal, $wideDecimal, $binary, $date, $time, $timestamp, $document, $list, 0.49,
        ])->fetchOne();

        self::assertEquals([
            $decimal,
            $wideDecimal,
            $binary,
            $date,
            $time,
            new DateTimeImmutable('1969-12-31T23:59:59.999Z'),
            (object) $document,
            $list,
            0.49,
        ], $row);
        self::assertSame('UTC', $row[5]->getTimezone()->getName());
        self::assertSame(0.49, $row[8]);
        self::assertNull($cursor->fetchOne());

        $cursor->execute("SELECT INTERVAL '3-7' YEAR TO MONTH, INTERVAL '3:0:7' HOUR TO SECOND");
        self::assertEquals([new Interval(43, 0), new Interval(0, 10_807_000)], $cursor->fetchOne());
    }

    public function testOtherQueryLanguages(): void
    {
        $table = self::temporaryName('php_languages');
        $cursor = $this->connection->cursor();
        $cursor->execute("CREATE TABLE $table (id INTEGER NOT NULL, name VARCHAR(20), PRIMARY KEY (id))");

        try {
            $cursor->execute("INSERT INTO $table (id, name) VALUES (?, ?)", [1, 'Maria']);
            $this->connection->commit();

            $cursor->executeAny('cql', "public.$table.id == 1 project public.$table.name");
            self::assertSame([['Maria']], $cursor->fetchAll());

            $cursor->executeAny('pig', "A = LOAD '$table'; B = FILTER A BY id == 1; DUMP B;");
            self::assertSame([[1, 'Maria']], $cursor->fetchAll());
        } finally {
            $this->connection->rollback();
            $cursor->execute("DROP TABLE $table");
            $cursor->close();
        }
    }

    public function testServerErrorLeavesTheConnectionUsable(): void
    {
        $cursor = $this->connection->cursor();
        try {
            $cursor->execute('SELEC 1');
            self::fail('An invalid SQL statement must report a server error.');
        } catch (ServerException) {
            $this->connection->rollback();
        }
        $cursor->execute('SELECT 7');
        self::assertSame([7], $cursor->fetchOne());
    }

    public function testClosingAConnectionClosesItsCursors(): void
    {
        $cursor = $this->connection->cursor();
        $cursor->execute('SELECT 1');
        $this->connection->close();
        $this->connection->close();

        $this->expectException(DriverException::class);
        $cursor->fetchOne();
    }

    public function testRejectsInvalidCredentials(): void
    {
        $this->expectException(DriverException::class);
        Connection::connectTcp(
            self::host(),
            self::port(),
            self::temporaryName('nonexistent'),
            'invalid',
        )->close();
    }

    public function testUnixSocketConnection(): void
    {
        $path = getenv('POLYPHENY_TEST_SOCKET');
        if ($path === false || $path === '') {
            self::markTestSkipped('Set POLYPHENY_TEST_SOCKET to test Unix socket connections.');
        }

        $unix = Connection::connectUnix($path, self::user(), self::password());
        try {
            $cursor = $unix->cursor();
            $cursor->execute('SELECT 1');
            self::assertSame([1], $cursor->fetchOne());
        } finally {
            $unix->close();
        }
    }

    public function testCollectionMetadataAndPendingWrites(): void
    {
        $namespace = self::temporaryName('php_metadata');
        $cursor = $this->connection->cursor();
        $cursor->execute("CREATE DOCUMENT NAMESPACE $namespace");

        try {
            $namespaces = $this->connection->searchNamespaces($namespace, 'DOCUMENT')->getNamespaces();
            self::assertCount(1, $namespaces);
            self::assertSame($namespace, $namespaces[0]->getNamespaceName());
            self::assertSame('DOCUMENT', $namespaces[0]->getNamespaceType());
            self::assertCount(0, $this->connection->searchNamespaces($namespace, 'RELATIONAL')->getNamespaces());
            self::assertCount(0, $this->connection->searchEntities($namespace)->getEntities());
            $cursor->executeAny('mongo', 'db.createCollection("events")', namespace: $namespace);
            $cursor->executeAny('mongo', 'db.createCollection("empty")', namespace: $namespace);
            $first = ['_id' => '1', 'profile' => ['flag' => true], 'label' => null, 'score' => 1, 'values' => ['x']];
            $second = ['_id' => '2', 'profile' => ['flag' => false], 'label' => 'done', 'score' => 2.5, 'values' => []];
            foreach ([$first, $second] as $document) {
                $cursor->executeAny(
                    'mongo',
                    'db.events.insertOne(' . json_encode($document, JSON_THROW_ON_ERROR) . ')',
                    namespace: $namespace,
                );
            }
            $this->connection->commit();

            $entities = $this->connection->searchEntities($namespace)->getEntities();
            self::assertCount(2, $entities);
            self::assertCount(0, $this->connection->searchEntities($namespace, 'missing')->getEntities());
            $events = $this->document($namespace, 'ev%');
            self::assertSame($namespace, $events->getNamespaceName());
            self::assertSame('events', $events->getCollectionName());
            self::assertTrue($events->hasSampleDocumentJson());
            $sample = json_decode($events->getSampleDocumentJson(), true, flags: JSON_THROW_ON_ERROR);
            self::assertContains($sample['_id'], ['1', '2']);
            self::assertEquals($sample['_id'] === '1' ? $first : $second, $sample);

            $fields = [];
            foreach ($events->getFields() as $field) {
                $fields[$field->getFieldPath()] = [$field->getTypeName(), $field->getIsOptional()];
            }
            self::assertCount(6, $events->getFields());
            self::assertEquals([
                '_id' => ['string', false],
                'profile' => ['object', false],
                'profile.flag' => ['bool', false],
                'label' => ['string', true],
                'score' => ['double', false],
                'values' => ['array', false],
            ], $fields);

            $empty = $this->document($namespace, 'empt_');
            self::assertCount(0, $empty->getFields());
            self::assertFalse($empty->hasSampleDocumentJson());

            $cursor->executeAny('mongo', 'db.empty.insertOne({"_id":"pending","flag":true})', namespace: $namespace);
            $pending = $this->document($namespace, 'empty');
            self::assertEquals(
                ['_id' => 'pending', 'flag' => true],
                json_decode($pending->getSampleDocumentJson(), true, flags: JSON_THROW_ON_ERROR),
            );
            $this->connection->rollback();
            self::assertFalse($this->document($namespace, 'empty')->hasSampleDocumentJson());

            $escaped = ['_id' => 'escaped', 'literal.key' => 1, 'literal' => ['key' => 'nested'], 'slash\\key' => true];
            $cursor->executeAny(
                'mongo',
                'db.empty.insertOne(' . json_encode($escaped, JSON_THROW_ON_ERROR) . ')',
                namespace: $namespace,
            );
            $this->connection->commit();
            $committed = $this->document($namespace, 'empty');
            $paths = [];
            foreach ($committed->getFields() as $field) {
                $paths[] = $field->getFieldPath();
            }
            self::assertEqualsCanonicalizing(['_id', 'literal\\.key', 'literal', 'literal.key', 'slash\\\\key'], $paths);
            self::assertEquals($escaped, json_decode($committed->getSampleDocumentJson(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $this->connection->rollback();
            $cursor->execute("DROP NAMESPACE $namespace");
            $cursor->close();
        }
    }

    public function testRelationalMetadataIncludesColumnsAndThePrimaryKey(): void
    {
        $tableName = self::temporaryName('php_table_metadata');
        $cursor = $this->connection->cursor();
        $cursor->execute("CREATE TABLE $tableName (id INTEGER NOT NULL, name VARCHAR(40), PRIMARY KEY (id))");

        try {
            $entities = $this->connection->searchEntities('public', $tableName)->getEntities();
            self::assertCount(1, $entities);
            $table = $entities[0]->getTable();
            self::assertNotNull($table);
            self::assertSame('public', $table->getNamespaceName());
            self::assertSame($tableName, $table->getTableName());
            self::assertCount(2, $table->getColumns());
            self::assertSame('id', $table->getColumns()[0]->getColumnName());
            self::assertSame('name', $table->getColumns()[1]->getColumnName());
            self::assertNotNull($table->getPrimaryKey());
            self::assertSame('id', $table->getPrimaryKey()->getColumns()[0]->getColumnName());
        } finally {
            $this->connection->rollback();
            $cursor->execute("DROP TABLE $tableName");
            $cursor->close();
        }
    }

    public function testMongoSourceExposesIndexPathsMethodsAndUniqueness(): void
    {
        $mongosh = getenv('POLYPHENY_TEST_MONGOSH');
        if ($mongosh === false || $mongosh === '') {
            self::markTestSkipped('Set POLYPHENY_TEST_MONGOSH to test real MongoDB source indexes.');
        }

        $host = self::environment('POLYPHENY_TEST_MONGO_HOST', '127.0.0.1');
        $port = self::environment('POLYPHENY_TEST_MONGO_PORT', '27017');
        $database = self::temporaryName('php_index_fixture');
        $adapter = self::temporaryName('php_mongo_source');
        $mongo = 'db.getSiblingDB(' . json_encode($database, JSON_THROW_ON_ERROR) . ')';
        $cursor = $this->connection->cursor();
        $created = false;

        try {
            self::runMongo($mongosh, $host, $port, <<<JAVASCRIPT
                const collection = $mongo.events;
                collection.insertOne({_id: 'event', patient_id: 'p1', age: 23, details: {code: 'aa'}});
                collection.createIndex({patient_id: 1}, {unique: true});
                collection.createIndex({age: 'hashed'});
                collection.createIndex({'details.code': 1});
                collection.createIndex({'\$**': 1});
                JAVASCRIPT);

            $settings = json_encode([
                'mode' => 'remote', 'host' => $host, 'port' => $port, 'database' => $database,
                'username' => '', 'password' => '', 'authSource' => '',
            ], JSON_THROW_ON_ERROR);
            $settings = str_replace("'", "''", $settings);
            $cursor->execute("ALTER ADAPTERS ADD \"$adapter\" USING 'MongoDB' AS 'Source' WITH '$settings'");
            $created = true;

            $deadline = hrtime(true) + 30_000_000_000;
            do {
                $namespaces = $this->connection->searchNamespaces($adapter, 'DOCUMENT')->getNamespaces();
                if (count($namespaces) === 1) {
                    break;
                }
                usleep(100_000);
            } while (hrtime(true) < $deadline);
            self::assertCount(1, $namespaces, 'The MongoDB source namespace was not discovered.');

            $document = $this->document($adapter, 'events');
            self::assertSame($adapter, $document->getNamespaceName());
            self::assertSame('events', $document->getCollectionName());
            $indexes = [];
            foreach ($document->getDocumentIndexes() as $index) {
                $indexes[$index->getFieldPath()] = [$index->getIndexType(), $index->getUnique()];
            }
            self::assertCount(5, $document->getDocumentIndexes());
            self::assertEquals([
                '_id' => ['btree', true],
                'patient_id' => ['btree', true],
                'age' => ['hash', false],
                'details.code' => ['btree', false],
                '$**' => ['wildcard', false],
            ], $indexes);
            self::assertSame('p1', json_decode($document->getSampleDocumentJson(), true, flags: JSON_THROW_ON_ERROR)['patient_id']);
        } finally {
            try {
                $this->connection->rollback();
                if ($created) {
                    $cursor->execute("ALTER ADAPTERS DROP \"$adapter\"");
                }
                $cursor->close();
            } finally {
                self::runMongo($mongosh, $host, $port, "$mongo.dropDatabase()");
            }
        }
    }

    private function document(string $namespace, string $pattern): Document
    {
        $entities = $this->connection->searchEntities($namespace, $pattern)->getEntities();
        self::assertCount(1, $entities);
        self::assertNull($entities[0]->getTable());
        self::assertNull($entities[0]->getGraph());
        $document = $entities[0]->getDocument();
        self::assertNotNull($document);

        return $document;
    }

    private static function runMongo(string $mongosh, string $host, string $port, string $javascript): void
    {
        $process = proc_open(
            [$mongosh, "mongodb://$host:$port/?directConnection=true&serverSelectionTimeoutMS=5000", '--quiet', '--eval', $javascript],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
        );
        self::assertIsResource($process, 'Could not start the MongoDB shell.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), (string) $output);
    }

    private static function connectTcp(bool $autoCommit = false): Connection
    {
        return Connection::connectTcp(self::host(), self::port(), self::user(), self::password(), $autoCommit);
    }

    private static function temporaryName(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }

    private static function host(): string
    {
        return self::environment('POLYPHENY_TEST_HOST', '127.0.0.1');
    }

    private static function port(): int
    {
        return (int) self::environment('POLYPHENY_TEST_PORT', '20590');
    }

    private static function user(): string
    {
        return self::environment('POLYPHENY_TEST_USER', 'pa');
    }

    private static function password(): string
    {
        return self::environment('POLYPHENY_TEST_PASSWORD', '');
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false ? $default : $value;
    }
}
