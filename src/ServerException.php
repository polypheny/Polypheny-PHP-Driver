<?php

declare(strict_types=1);

namespace Polypheny;

/** An error reported by the Polypheny server. */
final class ServerException extends DriverException
{
    private ?string $sqlState = null;
    private ?int $serverErrorCode = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $sqlState = null,
        ?int $serverErrorCode = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->sqlState = $sqlState;
        $this->serverErrorCode = $serverErrorCode;
    }

    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    public function getServerErrorCode(): ?int
    {
        return $this->serverErrorCode;
    }
}
