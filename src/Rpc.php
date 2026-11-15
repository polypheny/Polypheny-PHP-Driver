<?php

declare(strict_types=1);

namespace Polypheny;

use Google\Protobuf\Internal\Message;
use Org\Polypheny\Prism\Request;
use Org\Polypheny\Prism\Response;

/** @internal */
final class Rpc
{
    private int $nextRequestId = 1;

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Send a request and drain its responses until the server marks one final.
     *
     * @param callable(Response): void|null $onIntermediate Observes preliminary responses during this call.
     */
    public function call(string $requestName, Message $request, ?callable $onIntermediate = null): Response
    {
        $envelope = new Request();
        $setter = preg_match('/^set[A-Z]/', $requestName) === 1
            ? $requestName
            : 'set' . str_replace('_', '', ucwords($requestName, '_'));
        if (!method_exists($envelope, $setter)) {
            throw new \InvalidArgumentException('Unknown Prism request field: ' . $requestName);
        }
        $envelope->{$setter}($request);
        if ($envelope->getType() === '') {
            throw new \InvalidArgumentException('A Prism request must set a request field.');
        }

        $requestId = $this->nextRequestId;
        $this->nextRequestId = $requestId === PHP_INT_MAX ? 1 : $requestId + 1;
        $envelope->setId($requestId);
        $this->transport->writeFrame($envelope->serializeToString());

        while (true) {
            $response = $this->readResponse($requestId);
            if ($response->getErrorResponse() !== null) {
                if (!$response->getLast()) {
                    $this->close();
                    throw new ProtocolException('Prism sent a non-final error response.');
                }
                throw new ServerException($response->getErrorResponse()->getMessage());
            }
            if ($response->getLast()) {
                return $response;
            }
            if ($onIntermediate !== null) {
                try {
                    $onIntermediate($response);
                } catch (\Throwable $exception) {
                    $this->close();
                    throw $exception;
                }
            }
        }
    }

    private function readResponse(int $requestId): Response
    {
        $payload = $this->transport->readFrame();
        $response = new Response();
        try {
            $response->mergeFromString($payload);
        } catch (\Throwable $exception) {
            $this->close();
            throw new ProtocolException('Could not decode the Prism response.', 0, $exception);
        }
        if ((string) $response->getId() !== (string) $requestId || $response->getType() === '') {
            $this->close();
            throw new ProtocolException('Prism sent an unexpected response to request ' . $requestId . '.');
        }

        return $response;
    }

    public function isClosed(): bool
    {
        return $this->transport->isClosed();
    }

    public function close(): void
    {
        $this->transport->close();
    }
}
