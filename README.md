# Polypheny PHP Driver

A PHP client for [Polypheny](https://polypheny.com/) using the Prism query interface.
It requires 64-bit PHP 8.2 or later and uses the published Prism 2.2 schema.
The server decides whether its Prism version is compatible during connection.
Document collection metadata requires a server with Prism 2.2 document metadata support.

## Installation

Install the driver from Packagist with Composer.

```sh
composer require polypheny/php-driver
```

## Connections and queries

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Polypheny\Connection;

$connection = Connection::connectTcp('127.0.0.1', 20590, 'pa', '');
// On Unix, connect to the local user's Polypheny socket with Connection::connectUnix().

try {
    $cursor = $connection->cursor();
    $cursor->execute('CREATE TABLE fruits (id INTEGER, name VARCHAR(50), PRIMARY KEY (id))');
    $cursor->executeMany('INSERT INTO fruits (id, name) VALUES (?, ?)', [[1, 'Orange'], [2, 'Pear']]);
    $connection->commit();

    $cursor->execute('SELECT name FROM fruits WHERE id >= :minimum ORDER BY id', ['minimum' => 1], fetchSize: 1);
    foreach ($cursor as $row) {
        echo $row[0], PHP_EOL;
    }

} finally {
    $connection->close();
}
```

`connectTcp()` uses Prism's plain TCP transport. `connectUnix()` defaults to
`~/.polypheny/polypheny-prism.sock` and accepts an explicit path. Connections use
manual transactions by default. Call `commit()` or `rollback()`, or pass
`autoCommit: true` when opening the connection. Closing the connection releases
its cursors and rolls back pending work. Polypheny may commit DDL automatically.
When a query spans several frames, call `commit()` or `rollback()` explicitly
after consuming the results, even in auto-commit mode.

`execute()` runs SQL. `executeAny()` accepts a Prism language name such as
`mongo`, `cql` or `pig`, and an optional namespace. A list binds positional
parameters, and a string-keyed array binds named parameters, where supported by
the query language. `executeMany()` runs SQL for each binding set and leaves the
last result on the cursor.

For a document namespace named `shop` with a collection named `orders`, use
`$cursor->executeAny('mongo', 'db.orders.find({})', fetchSize: 10, namespace: 'shop')`.

`fetchOne()` returns a positional array for a relational row, a `stdClass` for a
document, or `null` at the end of a result set. `fetchMany()`, `fetchAll()` and
iteration fetch more Prism frames as needed. `setArraySize()` changes the default
row count for `fetchMany()`. `description()` returns column metadata for relational
results. `rowCount()` returns the scalar result of a statement or `-1` when a
result set is returned. Omit `fetchSize` or pass `0` to use the server's batch
size. Pass `-1` to request the entire result in one frame.

## Values and metadata

The connector maps nulls, booleans, integers, floating-point values, strings,
lists, and documents to Prism values. Use associative arrays or `stdClass` for
document input and `new stdClass()` for an empty document. Binary and decimal
values retain their original precision and bytes.

| Value | PHP representation |
| --- | --- |
| Decimal | `new Polypheny\Decimal('123.4500')`, convertible to a string |
| Binary | `new Polypheny\Binary($bytes)`, with a `bytes` property |
| Date and time | `new Polypheny\Date('2026-01-01')`, `new Polypheny\Time('12:00:00.123')` |
| Timestamp | `DateTimeInterface` input and UTC `DateTimeImmutable` output, at millisecond precision |
| Interval result | `Polypheny\Interval`, with `months` and `milliseconds` properties |

`searchNamespaces()` and `searchEntities()` return typed Prism responses.

```php
$response = $connection->searchEntities('shop', 'orders');
foreach ($response->getEntities() as $entity) {
    $document = $entity->getDocument();
    if ($document !== null) {
        foreach ($document->getFields() as $field) {
            printf("%s: %s\n", $field->getFieldPath(), $field->getTypeName());
        }
    }
}
```

Catch `Polypheny\DriverException` for driver and transport errors.
`Polypheny\ServerException` and `Polypheny\ProtocolException` specialize it for
Prism error responses and malformed protocol data, respectively.

## Development and tests

```sh
composer install
composer test
POLYPHENY_TEST_INTEGRATION=1 composer test:integration
```

The integration suite runs against a Prism 2.2 Polypheny instance. Its defaults
are TCP host `127.0.0.1`, port `20590`, user `pa` and an empty password.
Set `POLYPHENY_TEST_HOST`, `POLYPHENY_TEST_PORT`, `POLYPHENY_TEST_USER` and
`POLYPHENY_TEST_PASSWORD` to override them. `POLYPHENY_TEST_SOCKET` enables a
Unix socket test. The MongoDB index test also needs a test replica set
reachable from both the test process and Polypheny. Set
`POLYPHENY_TEST_MONGOSH` to a shell executable and, if needed,
`POLYPHENY_TEST_MONGO_HOST` and `POLYPHENY_TEST_MONGO_PORT`.

The schema in `proto/` is from the [Prism 2.2 release](https://github.com/polypheny/Polypheny-Prism-API/tree/2.2/org/polypheny/prism)
at commit `ecbefcb359c017038ded4fad1d03321ebcfbb8a4`. To regenerate the PHP
bindings, run `scripts/generate-proto.sh` with protoc 28.2.
