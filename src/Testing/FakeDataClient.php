<?php

namespace Aviato\Laravel\Testing;

use Aviato\Laravel\Data\DataClient;
use Aviato\Laravel\Exceptions\RpcException;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * An in-memory Data API for tests. Filters support equality, `$eq`, `$ne` and `$in` on
 * top-level fields; other operators throw.
 */
final class FakeDataClient implements DataClient
{
    /** @var array<string, array<string, array<string, mixed>>> collection => id => record */
    private array $records = [];

    /** @var array<string, string> */
    private array $primaryKeys = [];

    /** @var list<array{operation: string, collection: string, id: string|null, values: array<string, mixed>}> */
    private array $writes = [];

    private int $nextId = 1;

    /** @param list<array<string, mixed>> $records */
    public function seed(string $collection, array $records, string $primaryKey = 'id'): self
    {
        $this->primaryKeys[$collection] = $primaryKey;
        foreach ($records as $record) {
            $this->records[$collection][$this->idOf($collection, $record)] = $record;
        }

        return $this;
    }

    public function list(string $collection, ?array $filter = null, string $sort = '', int $limit = 50, int $offset = 0, array $fields = []): array
    {
        $records = array_values(array_filter($this->records[$collection] ?? [], fn (array $record): bool => $this->matches($record, $filter ?? [])));
        if ($sort !== '') {
            $descending = str_starts_with($sort, '-');
            $field = ltrim($sort, '-+');
            usort($records, fn (array $left, array $right): int => ($left[$field] ?? null) <=> ($right[$field] ?? null));
            if ($descending) {
                $records = array_reverse($records);
            }
        }
        $page = array_slice($records, $offset, $limit);
        if ($fields !== []) {
            $page = array_map(fn (array $record): array => array_intersect_key($record, array_flip([...$fields, $this->primaryKey($collection)])), $page);
        }

        return ['records' => $page, 'total' => count($records)];
    }

    /** @return list<array<string, mixed>> */
    public function records(string $collection): array
    {
        return array_values($this->records[$collection] ?? []);
    }

    public function get(string $collection, string $id): ?array
    {
        return $this->records[$collection][$id] ?? null;
    }

    public function create(string $collection, array $values): array
    {
        $key = $this->primaryKey($collection);
        $values[$key] ??= 'fake_'.$this->nextId++;
        $this->records[$collection][$this->idOf($collection, $values)] = $values;
        $this->writes[] = ['operation' => 'create', 'collection' => $collection, 'id' => $this->idOf($collection, $values), 'values' => $values];

        return $values;
    }

    public function update(string $collection, string $id, array $values): array
    {
        $record = $this->records[$collection][$id] ?? throw RpcException::notFound("Unknown record {$collection}/{$id}");
        $this->records[$collection][$id] = array_merge($record, $values);
        $this->writes[] = ['operation' => 'update', 'collection' => $collection, 'id' => $id, 'values' => $values];

        return $this->records[$collection][$id];
    }

    public function delete(string $collection, string $id): void
    {
        if (! isset($this->records[$collection][$id])) {
            throw RpcException::notFound("Unknown record {$collection}/{$id}");
        }
        unset($this->records[$collection][$id]);
        $this->writes[] = ['operation' => 'delete', 'collection' => $collection, 'id' => $id, 'values' => []];
    }

    /** @param array<string, mixed> $values Expected subset of the created values. */
    public function assertCreated(string $collection, array $values = []): self
    {
        Assert::assertTrue($this->written('create', $collection, null, $values), "No {$collection} record was created with ".json_encode($values).'.');

        return $this;
    }

    /** @param array<string, mixed> $values Expected subset of the updated values. */
    public function assertUpdated(string $collection, string|int $id, array $values = []): self
    {
        Assert::assertTrue($this->written('update', $collection, (string) $id, $values), "{$collection}/{$id} was not updated with ".json_encode($values).'.');

        return $this;
    }

    public function assertDeleted(string $collection, string|int $id): self
    {
        Assert::assertTrue($this->written('delete', $collection, (string) $id, []), "{$collection}/{$id} was not deleted.");

        return $this;
    }

    public function assertNothingWritten(): self
    {
        Assert::assertSame([], $this->writes, 'Records were written through the Data API.');

        return $this;
    }

    /** @return list<array{operation: string, collection: string, id: string|null, values: array<string, mixed>}> */
    public function writes(): array
    {
        return $this->writes;
    }

    /** @param array<string, mixed> $values */
    private function written(string $operation, string $collection, ?string $id, array $values): bool
    {
        foreach ($this->writes as $write) {
            if ($write['operation'] === $operation && $write['collection'] === $collection && ($id === null || $write['id'] === $id)
                && array_intersect_key($write['values'], $values) == $values) {
                return true;
            }
        }

        return false;
    }

    private function primaryKey(string $collection): string
    {
        return $this->primaryKeys[$collection] ?? 'id';
    }

    /** @param array<string, mixed> $record */
    private function idOf(string $collection, array $record): string
    {
        $id = $record[$this->primaryKey($collection)] ?? null;

        return is_scalar($id) ? (string) $id : throw new LogicException("Records of {$collection} need a scalar {$this->primaryKey($collection)}.");
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $filter
     */
    private function matches(array $record, array $filter): bool
    {
        foreach ($filter as $field => $condition) {
            $value = $record[$field] ?? null;
            if (! is_array($condition)) {
                if ($value != $condition) {
                    return false;
                }

                continue;
            }
            foreach ($condition as $operator => $operand) {
                $ok = match ($operator) {
                    '$eq' => $value == $operand,
                    '$ne' => $value != $operand,
                    '$in' => is_array($operand) && in_array($value, $operand, false),
                    default => throw new LogicException("FakeDataClient does not support the {$operator} operator."),
                };
                if (! $ok) {
                    return false;
                }
            }
        }

        return true;
    }
}
