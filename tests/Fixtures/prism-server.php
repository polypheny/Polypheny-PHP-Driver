<?php

declare(strict_types=1);

use Org\Polypheny\Prism\CommitResponse;
use Org\Polypheny\Prism\ConnectionCheckResponse;
use Org\Polypheny\Prism\ConnectionResponse;
use Org\Polypheny\Prism\CloseStatementResponse;
use Org\Polypheny\Prism\DisconnectResponse;
use Org\Polypheny\Prism\ErrorResponse;
use Org\Polypheny\Prism\Frame;
use Org\Polypheny\Prism\GraphElement;
use Org\Polypheny\Prism\GraphFrame;
use Org\Polypheny\Prism\ProtoNode;
use Org\Polypheny\Prism\Request;
use Org\Polypheny\Prism\Response;
use Org\Polypheny\Prism\RollbackResponse;
use Org\Polypheny\Prism\StatementResponse;
use Org\Polypheny\Prism\StatementResult;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @param resource $peer */
function readBytes($peer, int $length): string
{
    $result = '';
    while (strlen($result) < $length) {
        $chunk = fread($peer, $length - strlen($result));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('The client did not send all expected bytes.');
        }
        $result .= $chunk;
    }

    return $result;
}

/** @param resource $peer */
function sendFragments($peer, string $data): void
{
    foreach (str_split($data, 3) as $chunk) {
        if (fwrite($peer, $chunk) !== strlen($chunk)) {
            throw new RuntimeException('Unable to write a response fragment.');
        }
        usleep(1_000);
    }
}

/** @param resource $peer */
function readRequest($peer, string $type): Request
{
    $header = unpack('Vlow/Vhigh', readBytes($peer, 8));
    if ($header['high'] !== 0 || $header['low'] < 1 || $header['low'] > 10_000) {
        throw new RuntimeException('The client sent an invalid frame header.');
    }

    $request = new Request();
    $request->mergeFromString(readBytes($peer, $header['low']));
    if ($request->getType() !== $type) {
        throw new RuntimeException('Expected ' . $type . ', received ' . $request->getType() . '.');
    }

    return $request;
}

/** @param resource $peer */
function sendResponse($peer, Response $response): void
{
    $data = $response->serializeToString();
    sendFragments($peer, pack('V2', strlen($data), 0) . $data);
}

$scenario = $argv[1];
$socketPath = $argv[2] ?? null;

try {
    $address = $socketPath === null ? 'tcp://127.0.0.1:0' : 'unix://' . $socketPath;
    $listener = stream_socket_server($address, $errorNumber, $errorMessage);
    if ($listener === false) {
        throw new RuntimeException('Unable to start the test server: ' . $errorMessage);
    }
    $endpoint = stream_socket_get_name($listener, false);
    $port = $socketPath === null ? substr(strrchr($endpoint, ':'), 1) : 'unix';
    fwrite(STDOUT, $port . "\n");
    fflush(STDOUT);

    $peer = stream_socket_accept($listener, 5);
    if ($peer === false) {
        throw new RuntimeException('The test server did not receive a connection.');
    }
    stream_set_timeout($peer, 5);
    $transportVersion = $socketPath === null ? "plain-v1@polypheny.com\n" : "unix-v1@polypheny.com\n";
    if ($scenario === 'invalid-handshake') {
        $transportVersion = rtrim($transportVersion, "\n") . 'x';
    }
    $greeting = chr(strlen($transportVersion)) . $transportVersion;
    sendFragments($peer, $greeting);
    if ($scenario === 'invalid-handshake') {
        exit(0);
    }
    if (readBytes($peer, strlen($greeting)) !== $greeting) {
        throw new RuntimeException('The client did not echo the Prism greeting.');
    }

    if ($scenario === 'statement-error') {
        $statement = readRequest($peer, 'execute_unparameterized_statement_request');
        sendResponse($peer, (new Response())->setId($statement->getId())->setStatementResponse(
            (new StatementResponse())->setStatementId(7),
        ));
        sendResponse($peer, (new Response())->setId($statement->getId())->setLast(true)->setErrorResponse(
            (new ErrorResponse())->setMessage('Expected test error'),
        ));

        $commit = readRequest($peer, 'commit_request');
        if ((int) $commit->getId() <= (int) $statement->getId()) {
            throw new RuntimeException('Expected a new correlation ID after the failed statement.');
        }
        sendResponse($peer, (new Response())->setId($commit->getId())->setLast(true)->setCommitResponse(new CommitResponse()));
        exit(0);
    }

    if ($scenario === 'legacy-graph') {
        $connect = readRequest($peer, 'connection_request');
        $version = $connect->getConnectionRequest();
        if ($version->getMajorApiVersion() !== 2 || $version->getMinorApiVersion() !== 3) {
            throw new RuntimeException('The driver did not advertise Prism 2.3.');
        }
        sendResponse($peer, (new Response())->setId($connect->getId())->setLast(true)->setConnectionResponse(
            (new ConnectionResponse())->setMajorApiVersion(2)->setMinorApiVersion(2)->setIsCompatible(true),
        ));

        $statement = readRequest($peer, 'execute_unparameterized_statement_request');
        $firstPage = (new Frame())->setGraphFrame(new GraphFrame())->setIsLast(false);
        sendResponse($peer, (new Response())->setId($statement->getId())->setLast(true)->setStatementResponse(
            (new StatementResponse())->setStatementId(7)->setResult((new StatementResult())->setFrame($firstPage)),
        ));

        $fetch = readRequest($peer, 'fetch_request');
        if ((int) $fetch->getFetchRequest()->getStatementId() !== 7 || $fetch->getFetchRequest()->getFetchSize() !== 1) {
            throw new RuntimeException('The driver did not preserve the cursor ID and fetch size.');
        }
        $node = (new GraphElement())->setNode((new ProtoNode())->setId('legacy')->setLabels(['Person']));
        $lastPage = (new Frame())->setGraphFrame((new GraphFrame())->setElement([$node]))->setIsLast(true);
        sendResponse($peer, (new Response())->setId($fetch->getId())->setLast(true)->setFrame($lastPage));

        $close = readRequest($peer, 'close_statement_request');
        sendResponse($peer, (new Response())->setId($close->getId())->setLast(true)
            ->setCloseStatementResponse(new CloseStatementResponse()));
        $rollback = readRequest($peer, 'rollback_request');
        sendResponse($peer, (new Response())->setId($rollback->getId())->setLast(true)
            ->setRollbackResponse(new RollbackResponse()));
        $disconnect = readRequest($peer, 'disconnect_request');
        sendResponse($peer, (new Response())->setId($disconnect->getId())->setLast(true)
            ->setDisconnectResponse(new DisconnectResponse()));
        exit(0);
    }

    $request = readRequest($peer, 'connection_check_request');
    switch ($scenario) {
        case 'fragmented':
        case 'wrong-id':
            $responseId = (int) $request->getId() + ($scenario === 'wrong-id' ? 1 : 0);
            sendResponse($peer, (new Response())->setId($responseId)->setLast(true)->setConnectionCheckResponse(
                new ConnectionCheckResponse(),
            ));
            break;
        case 'oversized-frame':
            sendFragments($peer, pack('V2', 0, 1));
            break;
        case 'truncated-frame':
            sendFragments($peer, pack('V2', 12, 0) . "\x08");
            break;
        default:
            throw new RuntimeException('Unknown test server scenario: ' . $scenario);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
