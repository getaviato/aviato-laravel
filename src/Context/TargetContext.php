<?php

namespace Aviato\Laravel\Context;

use Aviato\Laravel\Data\DataClient;
use Closure;

/** The records an action or its form applies to. */
abstract class TargetContext extends Context
{
    /**
     * @param  Closure(Caller): DataClient  $dataClientFactory
     * @param  list<string>  $recordIds  Selected record ids (single and bulk actions); composite keys are encoded "a|b".
     * @param  array<string, mixed>|null  $filter  Global actions: the current filter (MongoDB query syntax).
     * @param  array<string, mixed>  $values  Form values.
     */
    public function __construct(
        Caller $caller,
        Closure $dataClientFactory,
        public readonly string $collection,
        public readonly array $recordIds = [],
        public readonly ?array $filter = null,
        public readonly string $segment = '',
        public readonly string $search = '',
        public readonly array $values = [],
    ) {
        parent::__construct($caller, $dataClientFactory);
    }

    /** The first selected record id (single actions). */
    public function recordId(): ?string
    {
        return $this->recordIds[0] ?? null;
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return data_get($this->values, $key, $default);
    }

    /**
     * The records the action runs on, read through the agent: the selected records, or the
     * records matching the current filter for global actions.
     *
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    public function records(array $fields = [], int $limit = 200): array
    {
        if ($this->recordIds !== []) {
            $records = [];
            foreach ($this->recordIds as $id) {
                $record = $this->data()->get($this->collection, $id);
                if ($record !== null) {
                    $records[] = $record;
                }
            }

            return $records;
        }

        return $this->data()->list($this->collection, filter: $this->filter, limit: $limit, fields: $fields)['records'];
    }
}
