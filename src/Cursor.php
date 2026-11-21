<?php

declare(strict_types=1);

namespace Polypheny;

use Generator;
use IteratorAggregate;
use Org\Polypheny\Prism\CloseStatementRequest;
use Org\Polypheny\Prism\ExecuteIndexedStatementRequest;
use Org\Polypheny\Prism\ExecuteNamedStatementRequest;
use Org\Polypheny\Prism\ExecuteUnparameterizedStatementRequest;
use Org\Polypheny\Prism\FetchRequest;
use Org\Polypheny\Prism\Frame;
use Org\Polypheny\Prism\GraphElement;
use Org\Polypheny\Prism\IndexedParameters;
use Org\Polypheny\Prism\NamedParameters;
use Org\Polypheny\Prism\PrepareStatementRequest;
use Org\Polypheny\Prism\ProtoDocument;
use Org\Polypheny\Prism\ProtoPolyType;
use Org\Polypheny\Prism\Response;
use Org\Polypheny\Prism\Row;
use Org\Polypheny\Prism\StatementResult;
use stdClass;
use Throwable;
use UnexpectedValueException;

/** @implements IteratorAggregate<int, array|stdClass|Node|Edge> */
final class Cursor implements IteratorAggregate
{
    private ?int $statementId = null;
    private ?string $frameType = null;
    private ?int $fetchSize = null;
    private bool $lastFrame = true;
    /** @var list<Row|ProtoDocument|GraphElement> */
    private array $rows = [];
    private int $nextRow = 0;
    private ?array $description = null;
    private int $rowCount = -1;
    private int $arraySize = 1;
    private ?Connection $connection;

    /** @internal Obtain cursors with Connection::cursor(). */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function execute(string $sql, ?array $parameters = null, ?int $fetchSize = null): self
    {
        return $this->executeAny('sql', $sql, $parameters, $fetchSize);
    }

    /**
     * Bind a list to positional placeholders, or a string-keyed array to named placeholders.
     */
    public function executeAny(
        string $language,
        string $query,
        ?array $parameters = null,
        ?int $fetchSize = null,
        ?string $namespace = null,
    ): self {
        $connection = $this->requireOpen();
        if ($fetchSize !== null && ($fetchSize < -2147483648 || $fetchSize > 2147483647)) {
            throw new DriverException('Fetch size must fit in a signed 32-bit integer.');
        }
        $this->reset();
        $this->fetchSize = $fetchSize === 0 ? null : $fetchSize;

        try {
            $result = $parameters === null
                ? $this->executeUnparameterized($connection, $language, $query, $namespace)
                : $this->executeParameterized($connection, $language, $query, $parameters, $namespace);
            if ($result->hasFrame()) {
                $this->acceptFrame($result->getFrame());
            } else {
                $this->rowCount = (int) $result->getScalar();
            }
        } catch (Throwable $error) {
            $this->discardFailedStatement();
            throw $error;
        }

        return $this;
    }

    private function executeUnparameterized(
        Connection $connection,
        string $language,
        string $query,
        ?string $namespace,
    ): StatementResult {
        $request = (new ExecuteUnparameterizedStatementRequest())->setLanguageName($language)->setStatement($query);
        if ($namespace !== null) {
            $request->setNamespaceName($namespace);
        }
        if ($this->fetchSize !== null) {
            $request->setFetchSize($this->fetchSize);
        }

        $statement = $connection->request(
            'ExecuteUnparameterizedStatementRequest',
            $request,
            'StatementResponse',
            function (Response $response): void {
                $statement = $response->getStatementResponse();
                if ($statement !== null) {
                    $this->statementId = $statement->getStatementId();
                }
            },
        );
        $this->statementId = $statement->getStatementId();
        return $statement->getResult() ?? throw new ProtocolException('The statement response has no result.');
    }

    private function executeParameterized(
        Connection $connection,
        string $language,
        string $query,
        array $parameters,
        ?string $namespace,
    ): StatementResult {
        $indexed = array_is_list($parameters);
        $encoded = [];
        foreach ($parameters as $key => $value) {
            if (!$indexed && !is_string($key)) {
                throw new DriverException('Named parameters must have string keys.');
            }
            $encoded[$key] = ValueCodec::encode($value);
        }

        $prepare = (new PrepareStatementRequest())->setLanguageName($language)->setStatement($query);
        if ($namespace !== null) {
            $prepare->setNamespaceName($namespace);
        }
        $signature = $connection->request(
            $indexed ? 'PrepareIndexedStatementRequest' : 'PrepareNamedStatementRequest',
            $prepare,
            'PreparedStatementSignature',
        );
        $this->statementId = $signature->getStatementId();

        if ($indexed) {
            $request = (new ExecuteIndexedStatementRequest())
                ->setParameters((new IndexedParameters())->setParameters($encoded));
            $requestName = 'ExecuteIndexedStatementRequest';
        } else {
            $request = (new ExecuteNamedStatementRequest())
                ->setParameters((new NamedParameters())->setParameters($encoded));
            $requestName = 'ExecuteNamedStatementRequest';
        }
        $request->setStatementId($this->statementId);
        if ($this->fetchSize !== null) {
            $request->setFetchSize($this->fetchSize);
        }
        return $connection->request($requestName, $request, 'StatementResult');
    }

    /** @param iterable<array> $parameters */
    public function executeMany(string $sql, iterable $parameters): self
    {
        $this->requireOpen();
        $this->reset();
        foreach ($parameters as $bindings) {
            if (!is_array($bindings)) {
                throw new DriverException('Each parameter set must be an array.');
            }
            $this->execute($sql, $bindings);
        }
        return $this;
    }

    private function acceptFrame(Frame $frame): void
    {
        $type = $frame->getResult();
        if ($this->frameType !== null && $type !== $this->frameType) {
            throw new ProtocolException('The result type changed between Prism frames.');
        }

        if ($type === 'relational_frame') {
            $result = $frame->getRelationalFrame();
            if ($this->description === null) {
                $this->description = [];
                foreach ($result->getColumnMeta() as $column) {
                    $typeId = $column->getTypeMeta()?->getProtoValueType();
                    try {
                        $columnType = $typeId === null ? null : ProtoPolyType::name($typeId);
                    } catch (UnexpectedValueException) {
                        $columnType = (string) $typeId;
                    }
                    $this->description[] = [
                        'name' => $column->getColumnLabel() ?: $column->getColumnName(),
                        'type' => $columnType,
                        'display_size' => $column->getLength(),
                        'precision' => $column->getPrecision(),
                        'scale' => $column->getScale(),
                        'nullable' => $column->getIsNullable(),
                        'original_name' => $column->getColumnName(),
                        'namespace' => $column->getNamespace() ?: $column->getSchemaName(),
                        'entity' => $column->getEntityName(),
                    ];
                }
            }
            $rows = $result->getRows();
        } elseif ($type === 'document_frame') {
            $rows = $frame->getDocumentFrame()->getDocuments();
        } elseif ($type === 'graph_frame') {
            $rows = $frame->getGraphFrame()->getElement();
        } else {
            throw new ProtocolException('The Prism frame has no result.');
        }

        $this->frameType = $type;
        $this->lastFrame = $frame->getIsLast();
        $this->rows = iterator_to_array($rows, false);
        $this->nextRow = 0;
    }

    public function fetchOne(): array|stdClass|Node|Edge|null
    {
        $connection = $this->requireOpen();
        if ($this->frameType === null) {
            throw new DriverException('There is no result set to fetch.');
        }

        try {
            while (!isset($this->rows[$this->nextRow])) {
                if ($this->lastFrame) {
                    return null;
                }
                $request = (new FetchRequest())->setStatementId($this->statementId);
                if ($this->fetchSize !== null) {
                    $request->setFetchSize($this->fetchSize);
                }
                $this->acceptFrame($connection->request('FetchRequest', $request, 'Frame'));
            }

            $row = $this->rows[$this->nextRow++];
            if ($row instanceof ProtoDocument) {
                return ValueCodec::decodeDocument($row);
            }
            if ($row instanceof GraphElement) {
                return ValueCodec::decodeGraphElement($row);
            }
            $decoded = [];
            foreach ($row->getValues() as $value) {
                $decoded[] = ValueCodec::decode($value);
            }
            return $decoded;
        } catch (Throwable $error) {
            $this->discardFailedStatement();
            throw $error;
        }
    }

    /** @return list<array|stdClass|Node|Edge> */
    public function fetchMany(?int $size = null): array
    {
        $this->requireOpen();
        $size ??= $this->arraySize;
        if ($size < 0) {
            throw new DriverException('Row count cannot be negative.');
        }
        $rows = [];
        while (count($rows) < $size && ($row = $this->fetchOne()) !== null) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array|stdClass|Node|Edge> */
    public function fetchAll(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    public function getIterator(): Generator
    {
        while (($row = $this->fetchOne()) !== null) {
            yield $row;
        }
    }

    public function setArraySize(int $size): self
    {
        if ($size < 1) {
            throw new DriverException('Array size must be positive.');
        }
        $this->arraySize = $size;
        return $this;
    }

    public function arraySize(): int
    {
        return $this->arraySize;
    }

    public function description(): ?array
    {
        return $this->description;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function close(): void
    {
        if ($this->connection === null) {
            return;
        }
        try {
            $this->reset();
        } finally {
            $this->connection->forgetCursor($this);
            $this->connection = null;
        }
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (Throwable) {
            // Use close() explicitly to handle errors during cleanup.
        }
    }

    private function discardFailedStatement(): void
    {
        try {
            $this->reset();
        } catch (Throwable) {
            // Preserve the original failure when releasing the failed statement.
        }
    }

    private function reset(): void
    {
        $statement = $this->statementId;
        $this->statementId = null;
        $this->rows = [];
        $this->nextRow = 0;
        $this->frameType = null;
        $this->lastFrame = true;
        $this->description = null;
        $this->rowCount = -1;

        if ($statement !== null && $this->connection !== null && !$this->connection->isClosed()) {
            $request = (new CloseStatementRequest())->setStatementId($statement);
            $this->connection->request('CloseStatementRequest', $request, 'CloseStatementResponse');
        }
    }

    private function requireOpen(): Connection
    {
        if ($this->connection === null || $this->connection->isClosed()) {
            throw new DriverException('Cursor is closed.');
        }
        return $this->connection;
    }
}
