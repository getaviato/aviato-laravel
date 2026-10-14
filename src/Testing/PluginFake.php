<?php

namespace Aviato\Laravel\Testing;

use Aviato\Laravel\Context\Caller;
use Aviato\Laravel\Context\DatasourceQuery;
use Aviato\Laravel\Context\FormResolution;
use Aviato\Laravel\Context\HookOutcome;
use Aviato\Laravel\Enums\HookOperation;
use Aviato\Laravel\Enums\HookTiming;
use Aviato\Laravel\Plugin;

/**
 * Calls your plugin's handlers in tests, without a connector: with a fake caller, and with the
 * Data API replaced by an in-memory {@see FakeDataClient}.
 *
 *     Aviato::fake()
 *         ->withRecords('customers', [['id' => '42', 'email' => 'ada@example.com']])
 *         ->executeAction('customers', 'Refund last invoice', ['42'], ['reason' => 'Duplicate'])
 *         ->assertSuccess('Refunded 42');
 */
final class PluginFake
{
    private Caller $caller;

    private readonly FakeDataClient $data;

    public function __construct(public readonly Plugin $plugin)
    {
        $this->caller = Caller::fake();
        $this->data = new FakeDataClient;
        $plugin->resolveDataClientUsing(fn (): FakeDataClient => $this->data);
    }

    /**
     * Calls the handlers as this caller (attributes override the fake caller's).
     *
     * @param  Caller|array<string, string>  $caller
     */
    public function actingAs(Caller|array $caller): self
    {
        $this->caller = $caller instanceof Caller ? $caller : Caller::fake($caller);

        return $this;
    }

    /** @param list<array<string, mixed>> $records */
    public function withRecords(string $collection, array $records, string $primaryKey = 'id'): self
    {
        $this->data->seed($collection, $records, $primaryKey);

        return $this;
    }

    public function data(): FakeDataClient
    {
        return $this->data;
    }

    /**
     * @param  list<string|int>  $recordIds
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|null  $filter
     */
    public function executeAction(string $collection, string $action, array $recordIds = [], array $values = [], ?array $filter = null, string $segment = '', string $search = ''): TestActionResult
    {
        return new TestActionResult($this->plugin->executeAction($collection, $action, $this->caller, $this->ids($recordIds), $filter, $segment, $search, $values));
    }

    /**
     * @param  list<string|int>  $recordIds
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|null  $filter
     */
    public function resolveForm(string $collection, string $action, array $recordIds = [], array $values = [], string $changedField = '', ?array $filter = null): FormResolution
    {
        return $this->plugin->resolveForm($collection, $action, $this->caller, $this->ids($recordIds), $filter, '', '', $values, $changedField);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<mixed> The field's value for each record.
     */
    public function computeField(string $collection, string $field, array $records): array
    {
        return array_map(fn (array $values): mixed => $values[$field] ?? null, $this->plugin->computeFields($collection, [$field], $records, $this->caller));
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @param  list<string|int>  $recordIds
     */
    public function runHooks(string $collection, HookTiming|string $timing, HookOperation|string $operation, ?array $values = null, array $recordIds = [], string $action = ''): HookOutcome
    {
        return $this->plugin->runHooks(
            $collection,
            is_string($timing) ? HookTiming::from($timing) : $timing,
            is_string($operation) ? HookOperation::from($operation) : $operation,
            $this->caller,
            $this->ids($recordIds),
            $values,
            $action,
        );
    }

    /** @return array{filter: array<string, mixed>}|array{ids: list<string>} */
    public function resolveSegment(string $collection, string $segment): array
    {
        return $this->plugin->resolveSegment($collection, $segment, $this->caller);
    }

    /** @return array<string, mixed> */
    public function search(string $collection, string $query): array
    {
        return $this->plugin->searchFilter($collection, $query, $this->caller);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function writeField(string $collection, string $field, mixed $value, array $record = []): array
    {
        return $this->plugin->writeField($collection, $field, $value, $record, $this->caller);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    public function computeChart(string $chart, ?array $filter = null): array
    {
        return $this->plugin->computeChart($chart, $filter, $this->caller);
    }

    /**
     * Lists the records of a custom collection as the connector does (translation strategy).
     *
     * @param  array<string, mixed>|null  $filter
     * @param  list<string>  $fields
     * @return array{records: list<array<string, mixed>>, total: int}
     */
    public function listCustomRecords(string $collection, ?array $filter = null, string $sort = '', ?int $limit = null, int $offset = 0, array $fields = [], bool $includeTotal = false): array
    {
        return $this->plugin->datasources()->list($collection, new DatasourceQuery($filter, DatasourceQuery::parseSort($sort), $limit, $offset, $fields, $includeTotal), $this->caller);
    }

    /**
     * A record of a custom collection; throws an RpcException `not_found` when there is none.
     *
     * @return array<string, mixed>
     */
    public function getCustomRecord(string $collection, string|int $id): array
    {
        return $this->plugin->datasources()->get($collection, (string) $id, $this->caller);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function createCustomRecord(string $collection, array $values): array
    {
        return $this->plugin->datasources()->create($collection, $values, $this->caller);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function updateCustomRecord(string $collection, string|int $id, array $values): array
    {
        return $this->plugin->datasources()->update($collection, (string) $id, $values, $this->caller);
    }

    public function deleteCustomRecord(string $collection, string|int $id): void
    {
        $this->plugin->datasources()->delete($collection, (string) $id, $this->caller);
    }

    /**
     * The changes of a replicated collection since `$cursor`, as the connector pulls them (without a
     * caller).
     *
     * @return array{records: list<array<string, mixed>>, deletedIds: list<string>, nextCursor: string, hasMore: bool}
     */
    public function listChanges(string $collection, string $cursor = ''): array
    {
        return $this->plugin->datasources()->listChanges($collection, $cursor);
    }

    /** @return list<array<string, mixed>> The custom collections as the connector receives them (JSON). */
    public function customCollections(): array
    {
        /** @var array{collections?: list<array<string, mixed>>} $described */
        $described = json_decode($this->plugin->datasources()->describe()->serializeToJsonString(), true, flags: JSON_THROW_ON_ERROR);

        return $described['collections'] ?? [];
    }

    /** @return array<string, mixed> The manifest as the connector receives it (JSON). */
    public function manifest(): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->plugin->manifest()->serializeToJsonString(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string|int>  $ids
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        return array_map(fn (string|int $id): string => (string) $id, $ids);
    }
}
