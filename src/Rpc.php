<?php

declare(strict_types=1);

namespace Polypheny;

use Google\Protobuf\Internal\Message;
use Org\Polypheny\Prism\Request;
use Org\Polypheny\Prism\Response;

/** @internal */
final class Rpc
{
    // A nested Prism value spans multiple message levels per map or list level.
    private const RESPONSE_RECURSION_LIMIT = 256;

    private int $nextRequestId = 1;
    private readonly bool $supportsRecursionLimit;

    public function __construct(private readonly Transport $transport)
    {
        $this->supportsRecursionLimit = (new \ReflectionMethod(Response::class, 'mergeFromString'))->getNumberOfParameters() >= 2;
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
            $error = $response->getErrorResponse();
            if ($error !== null) {
                if (!$response->getLast()) {
                    $this->close();
                    throw new ProtocolException('Prism sent a non-final error response.');
                }
                $details = $error->getDetails();
                throw new ServerException(
                    $error->getMessage(),
                    sqlState: $details?->hasState() ? $details->getState() : null,
                    serverErrorCode: $details?->hasErrorCode() ? $details->getErrorCode() : null,
                );
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
            if ($this->supportsRecursionLimit) {
                $response->mergeFromString($payload, self::RESPONSE_RECURSION_LIMIT);
            } else {
                $response->mergeFromString($payload);
            }
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
