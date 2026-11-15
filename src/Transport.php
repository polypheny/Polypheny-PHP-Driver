<?php

declare(strict_types=1);

namespace Polypheny;

/** @internal */
final class Transport
{
    private const TCP_VERSION = "plain-v1@polypheny.com\n";
    private const UNIX_VERSION = "unix-v1@polypheny.com\n";
    // The server uses Java buffers with signed 32-bit lengths.
    private const MAX_FRAME_BYTES = 2_147_483_647;
    private const CHUNK_BYTES = 65_536;

    /** @var resource|null */
    private $stream;

    /** @param resource $stream */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    public static function tcp(string $host, int $port = 20590, float $timeout = 30.0): self
    {
        if ($host === '' || preg_match('~[\x00-\x20/\\\\?#@]~', $host) === 1) {
            throw new \InvalidArgumentException('Provide a hostname or an IP address for the Prism TCP connection.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('The Prism TCP port must be between 1 and 65535.');
        }
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        return self::open("tcp://{$host}:{$port}", self::TCP_VERSION, $timeout);
    }

    public static function unix(?string $path = null, float $timeout = 30.0): self
    {
        if (!in_array('unix', stream_get_transports(), true)) {
            throw new DriverException('Unix sockets are unavailable in this PHP runtime. Use Prism TCP.');
        }
        if ($path === null) {
            $userHome = getenv('HOME');
            if ($userHome === false || $userHome === '') {
                throw new \InvalidArgumentException('Provide a Unix socket path when the home directory is unavailable.');
            }
            $path = rtrim($userHome, '/') . '/.polypheny/polypheny-prism.sock';
        }
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Provide a valid Prism Unix socket path.');
        }

        return self::open('unix://' . $path, self::UNIX_VERSION, $timeout);
    }

    private static function open(string $address, string $version, float $timeout): self
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout >= (float) PHP_INT_MAX) {
            throw new \InvalidArgumentException('The Prism connection timeout must be a positive number of seconds.');
        }

        $seconds = (int) $timeout;
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        if ($seconds === 0 && $microseconds === 0) {
            $microseconds = 1;
        }

        $context = stream_context_create(
            $version === self::TCP_VERSION ? ['socket' => ['tcp_nodelay' => true]] : [],
        );
        $stream = @stream_socket_client($address, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            $message = $error !== '' ? $error : "socket error {$errno}";
            throw new DriverException('Could not connect to Polypheny: ' . $message);
        }

        $transport = new self($stream);
        try {
            if (!stream_set_timeout($stream, $seconds, $microseconds)) {
                throw new DriverException('Could not configure the Prism socket timeout.');
            }
            $transport->exchangeVersion($version);
        } catch (\Throwable $exception) {
            $transport->close();
            throw $exception;
        }

        return $transport;
    }

    private function exchangeVersion(string $expected): void
    {
        $lengthByte = $this->readExactly(1);
        $length = ord($lengthByte);
        if ($length < 1 || $length > 127) {
            throw new ProtocolException('Invalid Prism transport version length.');
        }

        $version = $this->readExactly($length);
        if ($version !== $expected) {
            throw new ProtocolException('Unsupported Prism transport version. Expected ' . rtrim($expected) . '.');
        }

        $this->writeAll($lengthByte . $version);
    }

    public function writeFrame(string $payload): void
    {
        $length = strlen($payload);
        if ($length < 1 || $length > self::MAX_FRAME_BYTES) {
            throw new ProtocolException('Invalid outgoing Prism frame length.');
        }

        // Prism prefixes each payload with an unsigned 64-bit little-endian length.
        $this->writeAll(pack('V2', $length, 0));
        $this->writeAll($payload);
    }

    public function readFrame(): string
    {
        $length = unpack('Vlow/Vhigh', $this->readExactly(8));
        if ($length['high'] !== 0 || $length['low'] < 1 || $length['low'] > self::MAX_FRAME_BYTES) {
            $this->close();
            throw new ProtocolException('Invalid incoming Prism frame length.');
        }

        return $this->readExactly($length['low']);
    }

    private function readExactly(int $length): string
    {
        $stream = $this->requireStream();
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($stream, min(self::CHUNK_BYTES, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                $this->failIo('reading');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function writeAll(string $data): void
    {
        $stream = $this->requireStream();
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset += $written) {
            $written = @fwrite($stream, substr($data, $offset, self::CHUNK_BYTES));
            if ($written === false || $written === 0) {
                $this->failIo('writing');
            }
        }
    }

    /** @return resource */
    private function requireStream()
    {
        if ($this->stream === null) {
            throw new DriverException('The Prism connection is closed.');
        }

        return $this->stream;
    }

    private function failIo(string $operation): never
    {
        $metadata = stream_get_meta_data($this->requireStream());
        $this->close();
        if ($metadata['timed_out']) {
            throw new DriverException('The Prism connection timed out while ' . $operation . '.');
        }

        throw new DriverException('The Prism connection was interrupted while ' . $operation . '.');
    }

    public function isClosed(): bool
    {
        return $this->stream === null;
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
