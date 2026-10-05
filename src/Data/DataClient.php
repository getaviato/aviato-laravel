<?php

namespace Aviato\Laravel\Data;

/**
 * Reads and writes records through the agent, under the caller's permissions and audit trail.
 * Plugin code must use it instead of querying the database directly.
 */
interface DataClient
{
    /**
     * @param  array<string, mixed>|null  $filter  MongoDB query syntax.
     * @param  list<string>  $fields
     * @return array{records: list<array<string, mixed>>, total: int}
     */
    public function list(string $collection, ?array $filter = null, string $sort = '', int $limit = 50, int $offset = 0, array $fields = []): array;

    /** @return array<string, mixed>|null */
    public function get(string $collection, string $id): ?array;

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function create(string $collection, array $values): array;

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(string $collection, string $id, array $values): array;

    public function delete(string $collection, string $id): void;
}
