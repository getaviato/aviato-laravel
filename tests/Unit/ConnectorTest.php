<?php

use Aviato\Laravel\Connector\ConnectorBinary;
use Aviato\Laravel\Connector\DatabaseUrl;
use Aviato\Laravel\Connector\Platform;

it('maps platforms to release asset names', function (string $family, string $machine, string $os, string $arch) {
    $platform = Platform::from($family, $machine);

    expect([$platform->os, $platform->arch])->toBe([$os, $arch]);
})->with([
    ['Darwin', 'arm64', 'darwin', 'arm64'],
    ['Linux', 'x86_64', 'linux', 'x64'],
    ['Linux', 'aarch64', 'linux', 'arm64'],
    ['Windows', 'AMD64', 'windows', 'x64'],
]);

it('expands the download URL template', function () {
    $binary = ConnectorBinary::fromConfig(['version' => '2.0.0', 'download_url' => 'https://gh.test/download/connector-v{version}/aviato-connector-{os}-{arch}{ext}', 'checksum_url' => '{url}.sha256', 'path' => '/tmp/connector'], new Platform('windows', 'x64'));

    expect($binary->downloadUrl())->toBe('https://gh.test/download/connector-v2.0.0/aviato-connector-windows-x64.exe')
        ->and($binary->checksumUrl())->toBe('https://gh.test/download/connector-v2.0.0/aviato-connector-windows-x64.exe.sha256');
});

it('builds database URLs from Laravel connections', function (array $connection, string $url) {
    expect(DatabaseUrl::fromConnection($connection))->toBe($url);
})->with([
    'postgres' => [['driver' => 'pgsql', 'host' => 'db', 'port' => 5433, 'database' => 'shop', 'username' => 'app', 'password' => 'a/b', 'sslmode' => 'require'], 'postgres://app:a%2Fb@db:5433/shop?sslmode=require'],
    'mysql' => [['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'shop', 'username' => 'root', 'password' => ''], 'mysql://root@127.0.0.1:3306/shop'],
    'mariadb read/write' => [['driver' => 'mariadb', 'write' => ['host' => ['primary']], 'host' => 'replica', 'port' => 3306, 'database' => 'shop', 'username' => 'u', 'password' => 'p'], 'mysql://u:p@primary:3306/shop'],
    'url' => [['driver' => 'pgsql', 'url' => 'pgsql://u:p@h:5432/d'], 'postgres://u:p@h:5432/d'],
    'sqlite' => [['driver' => 'sqlite', 'database' => '/var/app/database.sqlite'], 'file:/var/app/database.sqlite'],
]);

it('rejects unsupported connections', function (array $connection) {
    DatabaseUrl::fromConnection($connection);
})->with([
    [['driver' => 'sqlsrv']],
    [['driver' => 'sqlite', 'database' => ':memory:']],
])->throws(InvalidArgumentException::class);

it('exposes a single PostgreSQL schema', function () {
    expect(DatabaseUrl::schema(['driver' => 'pgsql', 'search_path' => 'shop']))->toBe('shop')
        ->and(DatabaseUrl::schema(['driver' => 'pgsql', 'search_path' => 'public']))->toBeNull()
        ->and(DatabaseUrl::schema(['driver' => 'pgsql', 'search_path' => 'a,b']))->toBeNull();
});
