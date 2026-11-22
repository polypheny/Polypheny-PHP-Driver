<?php

declare(strict_types=1);

namespace Polypheny\Tests;

use Org\Polypheny\Prism\ConnectionCheckRequest;
use Org\Polypheny\Prism\ConnectionCheckResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polypheny\Connection;
use Polypheny\DriverException;
use Polypheny\Node;
use Polypheny\ProtocolException;
use Polypheny\Rpc;
use Polypheny\ServerException;
use Polypheny\Transport;
use UnexpectedValueException;

final class TransportTest extends TestCase
{
    /** @var resource|null */
    private $fixture = null;

    /** @var list<resource> */
    private array $fixturePipes = [];
    private ?Transport $transport = null;
    private ?string $socketPath = null;

    protected function tearDown(): void
    {
        $this->transport?->close();
        if (is_resource($this->fixture)) {
            $error = stream_get_contents($this->fixturePipes[2]);
            fclose($this->fixturePipes[1]);
            fclose($this->fixturePipes[2]);
            $status = proc_close($this->fixture);
            if ($this->socketPath !== null && file_exists($this->socketPath)) {
                unlink($this->socketPath);
            }
            self::assertSame(0, $status, is_string($error) ? $error : 'Unable to read the test server error.');
        }
    }

    public function testTcpHandshakeAndResponseAreReadAcrossFragments(): void
    {
        $this->assertFragmentedRoundTrip($this->startServer('fragmented'));
    }

    public function testUnixHandshakeAndResponseAreReadAcrossFragments(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !in_array('unix', stream_get_transports(), true)) {
            self::markTestSkipped('Unix domain sockets are unavailable.');
        }
        $this->assertFragmentedRoundTrip($this->startServer('fragmented', unix: true));
    }

    public function testPrism24ClientReadsLegacyGraphFramesFromACompatiblePrism22Server(): void
    {
        $port = $this->startFixture('legacy-graph');
        $connection = Connection::connectTcp(port: $port, timeout: 5);
        try {
            self::assertSame('2.2', $connection->serverVersion());
            $cursor = $connection->cursor()->executeAny('cypher', 'MATCH (n) RETURN n', fetchSize: 1);
            self::assertNull($cursor->description());
            self::assertSame(-1, $cursor->rowCount());

            $node = $cursor->fetchOne();
            self::assertInstanceOf(Node::class, $node);
            self::assertSame('legacy', $node->id);
            self::assertSame(['Person'], $node->labels);
            self::assertNull($cursor->fetchOne());
        } finally {
            $connection->close();
        }
    }

    /** @return iterable<string, array{string, class-string<\Throwable>, string}> */
    public static function graphFetchFailures(): iterable
    {
        yield 'server error' => ['graph-fetch-error', ServerException::class, 'Graph result failed during fetch.'];
        yield 'invalid element' => [
            'malformed-graph-page', UnexpectedValueException::class, 'Prism graph element has no node or edge.',
        ];
        yield 'frame type changes' => [
            'wrong-graph-frame', ProtocolException::class, 'The result type changed between Prism frames.',
        ];
    }

    /** @param class-string<\Throwable> $expectedException */
    #[DataProvider('graphFetchFailures')]
    public function testGraphFetchFailureDiscardsTheResultAndAllowsCursorReuse(
        string $scenario,
        string $expectedException,
        string $expectedMessage,
    ): void {
        $port = $this->startFixture($scenario);
        $connection = Connection::connectTcp(port: $port, timeout: 5);
        try {
            $cursor = $connection->cursor()->executeAny('cypher', 'MATCH (n) RETURN n', fetchSize: 1);
            try {
                $cursor->fetchOne();
                self::fail('The invalid graph result was accepted.');
            } catch (ServerException | ProtocolException | UnexpectedValueException $error) {
                self::assertInstanceOf($expectedException, $error);
                self::assertSame($expectedMessage, $error->getMessage());
                if ($error instanceof ServerException) {
                    self::assertNull($error->getSqlState());
                    self::assertSame(9002, $error->getServerErrorCode());
                    self::assertSame(0, $error->getCode());
                }
            }

            try {
                $cursor->fetchOne();
                self::fail('The cursor retained the failed result.');
            } catch (DriverException $error) {
                self::assertSame('There is no result set to fetch.', $error->getMessage());
            }
            self::assertFalse($connection->isClosed());
            $cursor->executeAny('cypher', 'CREATE (:Recovered)');
            self::assertSame(1, $cursor->rowCount());
        } finally {
            $connection->close();
        }
    }

    public function testInvalidTransportGreetingIsRejected(): void
    {
        $this->expectException(ProtocolException::class);
        $this->startServer('invalid-handshake');
    }

    public function testServerExceptionKeepsTheInheritedConstructorArguments(): void
    {
        $previous = new \RuntimeException('Cause');
        $exception = new ServerException(message: 'Original message', code: 42, previous: $previous);

        self::assertSame('Original message', $exception->getMessage());
        self::assertSame(42, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertNull($exception->getSqlState());
        self::assertNull($exception->getServerErrorCode());
    }

    public function testConnectReportsAServerErrorWithAnOptionalCode(): void
    {
        $port = $this->startFixture('login-error');
        try {
            Connection::connectTcp(port: $port, timeout: 5);
            self::fail('The connection was accepted.');
        } catch (ServerException $exception) {
            self::assertSame('Wrong username or password', $exception->getMessage());
            self::assertSame('28000', $exception->getSqlState());
            self::assertNull($exception->getServerErrorCode());
        }
    }

    /** @return iterable<string, array{string, ?string, ?int}> */
    public static function statementErrors(): iterable
    {
        yield 'legacy message only' => ['legacy-statement-error', null, null];
        yield 'internal error with explicit code zero' => ['statement-error', 'XX000', 0];
    }

    #[DataProvider('statementErrors')]
    public function testStatementErrorPreservesTheConnectionAndPreliminaryStatementId(
        string $scenario,
        ?string $sqlState,
        ?int $serverErrorCode,
    ): void {
        $port = $this->startFixture($scenario);
        $connection = Connection::connectTcp(port: $port, timeout: 5);
        try {
            $cursor = $connection->cursor();
            try {
                $cursor->execute('SELECT 1');
                self::fail('The server did not report its error.');
            } catch (ServerException $exception) {
                self::assertSame("Expected test error (SQLSTATE 22012, code 42)\n", $exception->getMessage());
                self::assertSame($sqlState, $exception->getSqlState());
                self::assertSame($serverErrorCode, $exception->getServerErrorCode());
            }
            self::assertFalse($connection->isClosed());
            $connection->commit();
        } finally {
            $connection->close();
        }
    }

    public function testNonFinalServerErrorClosesTheConnection(): void
    {
        $rpc = $this->startServer('non-final-error');
        try {
            $rpc->call('connection_check_request', new ConnectionCheckRequest());
            self::fail('The non-final error was accepted.');
        } catch (ProtocolException $exception) {
            self::assertSame('Prism sent a non-final error response.', $exception->getMessage());
            self::assertTrue($rpc->isClosed());
        }
    }

    /** @return iterable<string, array{string, class-string<DriverException>}> */
    public static function invalidResponses(): iterable
    {
        yield 'mismatched request ID' => ['wrong-id', ProtocolException::class];
        yield 'length exceeds signed 32-bit range' => ['oversized-frame', ProtocolException::class];
        yield 'incomplete payload' => ['truncated-frame', DriverException::class];
    }

    /** @param class-string<DriverException> $expectedException */
    #[DataProvider('invalidResponses')]
    public function testInvalidResponsesCloseTheConnection(string $scenario, string $expectedException): void
    {
        $rpc = $this->startServer($scenario);

        try {
            $rpc->call('connection_check_request', new ConnectionCheckRequest());
            self::fail('The malformed response was accepted.');
        } catch (DriverException $exception) {
            self::assertInstanceOf($expectedException, $exception);
            self::assertTrue($rpc->isClosed());
        }
    }

    private function assertFragmentedRoundTrip(Rpc $rpc): void
    {
        $response = $rpc->call('connection_check_request', new ConnectionCheckRequest());
        self::assertInstanceOf(ConnectionCheckResponse::class, $response->getConnectionCheckResponse());
        self::assertSame('1', (string) $response->getId());
    }

    private function startServer(string $scenario, bool $unix = false): Rpc
    {
        $port = $this->startFixture($scenario, $unix);
        $this->transport = $unix
            ? Transport::unix($this->socketPath, timeout: 5)
            : Transport::tcp('127.0.0.1', $port, timeout: 5);

        return new Rpc($this->transport);
    }

    private function startFixture(string $scenario, bool $unix = false): int
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Starting the Prism fixture requires proc_open.');
        }

        $command = [PHP_BINARY, __DIR__ . '/Fixtures/prism-server.php', $scenario];
        if ($unix) {
            $socketPath = tempnam(sys_get_temp_dir(), 'prism-');
            self::assertNotFalse($socketPath);
            unlink($socketPath);
            $this->socketPath = $socketPath;
            $command[] = $socketPath;
        }
        $this->fixture = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->fixturePipes,
        );
        self::assertIsResource($this->fixture);
        fclose($this->fixturePipes[0]);

        $endpoint = fgets($this->fixturePipes[1]);
        self::assertNotFalse($endpoint, 'The test server did not report a ready endpoint.');
        return (int) trim($endpoint);
    }
}
