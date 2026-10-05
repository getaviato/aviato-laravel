<?php

namespace Aviato\Laravel\Agent;

use InvalidArgumentException;

/** Builds the agent's AVIATO_DATABASE_URL from a Laravel database connection. */
final class DatabaseUrl
{
    /** @param array<string, mixed> $connection A `database.connections.*` entry. */
    public static function fromConnection(array $connection): string
    {
        $driver = is_string($connection['driver'] ?? null) ? $connection['driver'] : '';
        $url = $connection['url'] ?? null;
        if (is_string($url) && $url !== '') {
            return self::normalizeScheme($url);
        }

        return match ($driver) {
            'pgsql' => self::network('postgres', $connection, 5432),
            'mysql', 'mariadb' => self::network('mysql', $connection, 3306),
            'sqlite' => self::sqlite($connection),
            default => throw new InvalidArgumentException("The Aviato agent does not support the {$driver} driver (use PostgreSQL, MySQL, MariaDB or SQLite)."),
        };
    }

    /**
     * The PostgreSQL schema to expose, when the connection sets a single search_path.
     *
     * @param  array<string, mixed>  $connection
     */
    public static function schema(array $connection): ?string
    {
        $path = $connection['search_path'] ?? $connection['schema'] ?? null;
        if (($connection['driver'] ?? null) !== 'pgsql' || ! is_string($path) || $path === '' || str_contains($path, ',')) {
            return null;
        }
        $path = trim($path, " \"'");

        return $path === 'public' ? null : $path;
    }

    private static function normalizeScheme(string $url): string
    {
        return (string) preg_replace(['#^pgsql://#i', '#^mariadb://#i'], ['postgres://', 'mysql://'], $url);
    }

    /** @param array<string, mixed> $connection */
    private static function network(string $scheme, array $connection, int $defaultPort): string
    {
        $write = is_array($connection['write'] ?? null) ? $connection['write'] : [];
        $host = $write['host'] ?? $connection['host'] ?? '127.0.0.1';
        $host = is_array($host) ? reset($host) : $host;
        $host = is_string($host) ? $host : '127.0.0.1';
        $port = $connection['port'] ?? $defaultPort;
        $username = is_scalar($connection['username'] ?? null) ? (string) $connection['username'] : '';
        $password = is_scalar($connection['password'] ?? null) ? (string) $connection['password'] : '';
        $database = is_scalar($connection['database'] ?? null) ? (string) $connection['database'] : '';

        $credentials = $username !== '' ? rawurlencode($username).($password !== '' ? ':'.rawurlencode($password) : '').'@' : '';
        $host = str_contains($host, ':') && ! str_starts_with($host, '[') ? "[{$host}]" : $host;
        $query = $scheme === 'postgres' && is_string($connection['sslmode'] ?? null) && $connection['sslmode'] !== ''
            ? '?sslmode='.rawurlencode($connection['sslmode'])
            : '';

        return "{$scheme}://{$credentials}{$host}:".(is_scalar($port) ? (string) $port : (string) $defaultPort).'/'.rawurlencode($database).$query;
    }

    /** @param array<string, mixed> $connection */
    private static function sqlite(array $connection): string
    {
        $database = is_string($connection['database'] ?? null) ? $connection['database'] : '';
        if ($database === '' || $database === ':memory:') {
            throw new InvalidArgumentException('The Aviato agent needs a SQLite database file, not an in-memory database.');
        }

        return 'file:'.(realpath($database) ?: $database);
    }
}
