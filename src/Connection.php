<?php

declare(strict_types=1);

namespace Polypheny;

use Google\Protobuf\Internal\Message;
use Org\Polypheny\Prism\CommitRequest;
use Org\Polypheny\Prism\ConnectionProperties;
use Org\Polypheny\Prism\ConnectionRequest;
use Org\Polypheny\Prism\DisconnectRequest;
use Org\Polypheny\Prism\EntitiesRequest;
use Org\Polypheny\Prism\EntitiesResponse;
use Org\Polypheny\Prism\NamespacesRequest;
use Org\Polypheny\Prism\NamespacesResponse;
use Org\Polypheny\Prism\RollbackRequest;
use Throwable;
use WeakMap;

final class Connection
{
    public const PRISM_MAJOR = 2;
    public const PRISM_MINOR = 3;

    /** @var WeakMap<Cursor, true> */
    private WeakMap $cursors;

    private function __construct(
        private readonly Rpc $rpc,
        private readonly string $serverVersion,
    ) {
        $this->cursors = new WeakMap();
    }

    public static function connectTcp(
        string $host = '127.0.0.1',
        int $port = 20590,
        ?string $username = 'pa',
        ?string $password = '',
        bool $autoCommit = false,
        float $timeout = 30.0,
    ): self {
        return self::open(Transport::tcp($host, $port, $timeout), $username, $password, $autoCommit);
    }

    public static function connectUnix(
        ?string $path = null,
        ?string $username = null,
        ?string $password = null,
        bool $autoCommit = false,
        float $timeout = 30.0,
    ): self {
        return self::open(Transport::unix($path, $timeout), $username, $password, $autoCommit);
    }

    private static function open(Transport $transport, ?string $username, ?string $password, bool $autoCommit): self
    {
        $rpc = new Rpc($transport);

        try {
            $properties = (new ConnectionProperties())->setIsAutoCommit($autoCommit);
            $request = (new ConnectionRequest())
                ->setMajorApiVersion(self::PRISM_MAJOR)
                ->setMinorApiVersion(self::PRISM_MINOR)
                ->setConnectionProperties($properties);
            if ($username !== null) {
                $request->setUsername($username);
            }
            if ($password !== null) {
                $request->setPassword($password);
            }
            $response = $rpc->call('ConnectionRequest', $request)->getConnectionResponse();
            if ($response === null) {
                throw new ProtocolException('Expected a Prism connection response.');
            }
            $server = $response->getMajorApiVersion() . '.' . $response->getMinorApiVersion();
            if (!$response->getIsCompatible()) {
                throw new ProtocolException('Prism server ' . $server . ' is incompatible with this Prism '
                    . self::PRISM_MAJOR . '.' . self::PRISM_MINOR . ' client.');
            }

            return new self($rpc, $server);
        } catch (Throwable $error) {
            $rpc->close();
            throw $error;
        }
    }

    public function serverVersion(): string
    {
        return $this->serverVersion;
    }

    public function cursor(): Cursor
    {
        $this->requireOpen();
        $cursor = new Cursor($this);
        $this->cursors[$cursor] = true;
        return $cursor;
    }

    public function commit(): void
    {
        $this->request('CommitRequest', new CommitRequest(), 'CommitResponse');
    }

    public function rollback(): void
    {
        $this->request('RollbackRequest', new RollbackRequest(), 'RollbackResponse');
    }

    public function searchNamespaces(?string $pattern = null, ?string $type = null): NamespacesResponse
    {
        $request = new NamespacesRequest();
        if ($pattern !== null) {
            $request->setNamespacePattern($pattern);
        }
        if ($type !== null) {
            $request->setNamespaceType($type);
        }
        return $this->request('NamespacesRequest', $request, 'NamespacesResponse');
    }

    public function searchEntities(string $namespace, ?string $pattern = null): EntitiesResponse
    {
        $request = (new EntitiesRequest())->setNamespaceName($namespace);
        if ($pattern !== null) {
            $request->setEntityPattern($pattern);
        }
        return $this->request('EntitiesRequest', $request, 'EntitiesResponse');
    }

    public function isClosed(): bool
    {
        return $this->rpc->isClosed();
    }

    public function close(): void
    {
        $cursors = [];
        foreach ($this->cursors as $cursor => $_) {
            $cursors[] = $cursor;
        }

        $failure = null;
        foreach ($cursors as $cursor) {
            try {
                $cursor->close();
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }

        if (!$this->isClosed()) {
            try {
                $this->rollback();
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }

        try {
            if (!$this->isClosed()) {
                $this->request('DisconnectRequest', new DisconnectRequest(), 'DisconnectResponse');
            }
        } catch (Throwable $error) {
            $failure ??= $error;
        } finally {
            $this->rpc->close();
        }

        if ($failure !== null) {
            throw $failure;
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

    /** @internal */
    public function forgetCursor(Cursor $cursor): void
    {
        unset($this->cursors[$cursor]);
    }

    /** @internal */
    public function request(
        string $requestName,
        Message $request,
        string $responseName,
        ?callable $onIntermediate = null,
    ): Message {
        $this->requireOpen();
        $response = $this->rpc->call($requestName, $request, $onIntermediate);
        $getter = 'get' . $responseName;
        $message = $response->$getter();
        if ($message === null) {
            $this->rpc->close();
            throw new ProtocolException('Expected ' . $responseName . ' in the Prism response.');
        }
        return $message;
    }

    private function requireOpen(): void
    {
        if ($this->isClosed()) {
            throw new DriverException('Connection is closed.');
        }
    }
}
