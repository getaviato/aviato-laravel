<?php

namespace Aviato\Laravel;

use Aviato\Laravel\Context\Caller;
use Aviato\Laravel\Context\DatasourceContext;
use Aviato\Laravel\Context\DatasourceQuery;
use Aviato\Laravel\Definitions\CustomCollectionDefinition;
use Aviato\Laravel\Definitions\DatasourceDefinition;
use Aviato\Laravel\Definitions\RelationHint;
use Aviato\Laravel\Exceptions\InvalidDefinitionException;
use Aviato\Laravel\Exceptions\RpcException;
use Aviato\Plugin\V1\CollectionCapabilities;
use Aviato\Plugin\V1\CustomCollection;
use Aviato\Plugin\V1\DatasourceStrategy;
use Aviato\Plugin\V1\DescribeCollectionsResponse;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Traversable;

/** The custom datasources of the plugin, and the calls to their handlers, by collection. */
final class Datasources
{
    /** @var list<DatasourceDefinition> */
    private array $definitions = [];

    public function add(DatasourceDefinition $definition): DatasourceDefinition
    {
        return $this->definitions[] = $definition;
    }

    public function isEmpty(): bool
    {
        foreach ($this->definitions as $definition) {
            if ($definition->collections !== []) {
                return false;
            }
        }

        return true;
    }

    public function describe(): DescribeCollectionsResponse
    {
        $collections = [];
        foreach ($this->collections() as [$definition, $collection]) {
            $collections[] = new CustomCollection([
                'name' => $collection->name,
                'fields' => $collection->fields,
                'primary_key' => $collection->primaryKey,
                'relations' => array_map(fn (RelationHint $relation) => $relation->toProto(), $collection->relations),
                'capabilities' => new CollectionCapabilities([
                    'filter_operators' => $collection->filterOperators,
                    'sort' => $collection->sort,
                    'count' => $collection->count,
                    'create' => $definition->create !== null,
                    'update' => $definition->update !== null,
                    'delete' => $definition->delete !== null,
                ]),
                'strategy' => $this->strategy($definition, $collection) === 'replication'
                    ? DatasourceStrategy::DATASOURCE_STRATEGY_REPLICATION
                    : DatasourceStrategy::DATASOURCE_STRATEGY_TRANSLATION,
                'replication_interval_seconds' => $collection->replicationIntervalSeconds,
            ]);
        }

        return new DescribeCollectionsResponse(['collections' => $collections]);
    }

    /** @return array{records: list<array<string, mixed>>, total: int} */
    public function list(string $collection, DatasourceQuery $query, ?Caller $caller): array
    {
        [$definition] = $this->require($collection);
        $result = $this->plain($this->call($definition->list, "list of {$collection}", $query, new DatasourceContext($collection, $caller)));
        if (is_array($result) && array_key_exists('records', $result)) {
            $records = $this->records($result['records'], "The list handler of {$collection}");
            $total = is_numeric($result['total'] ?? null) ? (int) $result['total'] : 0;
        } elseif (is_array($result) && array_is_list($result)) {
            $records = $this->records($result, "The list handler of {$collection}");
            $total = 0;
        } else {
            throw new InvalidDefinitionException("The list handler of {$collection} must return ['records' => [...], 'total' => int] or a list of records, got ".get_debug_type($result).'.');
        }

        return ['records' => $records, 'total' => max($total, count($records))];
    }

    /** @return array<string, mixed> */
    public function get(string $collection, string $id, ?Caller $caller): array
    {
        [$definition, $declared] = $this->require($collection);
        $context = new DatasourceContext($collection, $caller);
        if ($definition->get !== null) {
            $record = $this->plain(($definition->get)($id, $context));
            $record = $record === null ? null : $this->record($record, "The get handler of {$collection}");
        } else {
            $record = null;
            foreach ($this->list($collection, new DatasourceQuery, $caller)['records'] as $candidate) {
                if ($this->encodeId($candidate, $declared->primaryKey) === $id) {
                    $record = $candidate;
                    break;
                }
            }
        }

        return $record ?? throw RpcException::notFound("No record {$id} in {$collection}");
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function create(string $collection, array $values, ?Caller $caller): array
    {
        [$definition] = $this->require($collection);
        if ($definition->create === null) {
            throw new RpcException('unimplemented', "{$collection} does not support creating records");
        }

        return $this->record(($definition->create)($values, new DatasourceContext($collection, $caller)), "The create handler of {$collection}");
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(string $collection, string $id, array $values, ?Caller $caller): array
    {
        [$definition] = $this->require($collection);
        if ($definition->update === null) {
            throw new RpcException('unimplemented', "{$collection} does not support updating records");
        }

        return $this->record(($definition->update)($id, $values, new DatasourceContext($collection, $caller)), "The update handler of {$collection}");
    }

    public function delete(string $collection, string $id, ?Caller $caller): void
    {
        [$definition] = $this->require($collection);
        if ($definition->delete === null) {
            throw new RpcException('unimplemented', "{$collection} does not support deleting records");
        }
        ($definition->delete)($id, new DatasourceContext($collection, $caller));
    }

    /** @return array{records: list<array<string, mixed>>, deletedIds: list<string>, nextCursor: string, hasMore: bool} */
    public function listChanges(string $collection, string $cursor): array
    {
        [$definition] = $this->require($collection);
        if ($definition->listChanges === null) {
            throw new RpcException('unimplemented', "{$collection} is not replicated");
        }
        $changes = $this->plain(($definition->listChanges)($cursor, new DatasourceContext($collection)));
        if (! is_array($changes) || ! is_scalar($changes['nextCursor'] ?? null)) {
            throw new InvalidDefinitionException("The listChanges handler of {$collection} must return ['records' => [...], 'deletedIds' => [...], 'nextCursor' => '…', 'hasMore' => bool].");
        }
        $deletedIds = is_array($changes['deletedIds'] ?? null) ? $changes['deletedIds'] : [];

        return [
            'records' => $this->records($changes['records'] ?? [], "The listChanges handler of {$collection}"),
            'deletedIds' => array_values(array_map(fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $deletedIds)),
            'nextCursor' => (string) $changes['nextCursor'],
            'hasMore' => (bool) ($changes['hasMore'] ?? false),
        ];
    }

    /**
     * The declared collections by name, checked.
     *
     * @return array<string, array{DatasourceDefinition, CustomCollectionDefinition}>
     */
    private function collections(): array
    {
        $collections = [];
        foreach ($this->definitions as $definition) {
            foreach ($definition->collections as $collection) {
                if (isset($collections[$collection->name])) {
                    throw new InvalidDefinitionException("Custom collection \"{$collection->name}\" is declared twice.");
                }
                if ($definition->list === null) {
                    throw new InvalidDefinitionException("The datasource of custom collection \"{$collection->name}\" has no list handler.");
                }
                if ($this->strategy($definition, $collection) === 'replication' && $definition->listChanges === null) {
                    throw new InvalidDefinitionException("Custom collection \"{$collection->name}\" is replicated: its datasource needs a listChanges handler.");
                }
                $collections[$collection->name] = [$definition, $collection];
            }
        }

        return $collections;
    }

    /** @return array{DatasourceDefinition, CustomCollectionDefinition} */
    private function require(string $collection): array
    {
        return $this->collections()[$collection] ?? throw RpcException::notFound("Unknown custom collection {$collection}");
    }

    private function strategy(DatasourceDefinition $definition, CustomCollectionDefinition $collection): string
    {
        return $collection->strategy ?? $definition->strategy;
    }

    private function call(?Closure $handler, string $what, mixed ...$arguments): mixed
    {
        return $handler !== null ? $handler(...$arguments) : throw new InvalidDefinitionException("No handler for the {$what}.");
    }

    /** Collections, models and other Arrayable values become arrays. */
    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Arrayable => $value->toArray(),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            $value instanceof Traversable => iterator_to_array($value, false),
            default => $value,
        };
    }

    /** @return array<string, mixed> */
    private function record(mixed $record, string $what): array
    {
        $record = $this->plain($record);
        if (! is_array($record)) {
            throw new InvalidDefinitionException("{$what} must return a record (an array), got ".get_debug_type($record).'.');
        }

        /** @var array<string, mixed> $record */
        return $record;
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $records, string $what): array
    {
        $records = $this->plain($records);
        if (! is_array($records)) {
            throw new InvalidDefinitionException("{$what} must return a list of records, got ".get_debug_type($records).'.');
        }

        return array_values(array_map(fn (mixed $record): array => $this->record($record, $what), $records));
    }

    /**
     * The record id the connector uses (`a|b` for composite keys).
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $primaryKey
     */
    private function encodeId(array $record, array $primaryKey): string
    {
        $parts = array_map(fn (string $key): string => is_scalar($record[$key] ?? null) ? (string) $record[$key] : '', $primaryKey);
        if (count($parts) > 1 && str_contains(implode('', $parts), '|')) {
            return '~'.implode('.', array_map('bin2hex', $parts));
        }

        return implode('|', $parts);
    }
}
