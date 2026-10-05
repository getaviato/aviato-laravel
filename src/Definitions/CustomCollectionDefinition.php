<?php

namespace Aviato\Laravel\Definitions;

use Aviato\Plugin\V1\CustomField;

/**
 * A collection served by a custom datasource.
 *
 *     $collection
 *         ->field('id', 'number', nullable: false, readOnly: true, hasDefault: true)
 *         ->field('status', 'enum', enumValues: ['open', 'closed'])
 *         ->relation('customer', 'belongsTo', 'customer_id', 'customers')
 *         ->capabilities(filterOperators: ['eq', 'in'], sort: true, count: true);
 */
final class CustomCollectionDefinition
{
    /** @var list<CustomField> */
    public array $fields = [];

    /** @var list<string> */
    public array $primaryKey = ['id'];

    /** @var list<RelationHint> */
    public array $relations = [];

    /** @var list<string> */
    public array $filterOperators = [];

    public bool $sort = false;

    public bool $count = false;

    /** Overrides the datasource's strategy when set. */
    public ?string $strategy = null;

    public int $replicationIntervalSeconds = 0;

    public function __construct(public readonly string $name) {}

    /**
     * @param  string  $type  A protocol field type: string, number, boolean, date, dateonly, time, json, uuid, enum, binary.
     * @param  list<string>  $enumValues
     */
    public function field(string $name, string $type = 'string', array $enumValues = [], bool $nullable = true, bool $readOnly = false, bool $hasDefault = false): self
    {
        $this->fields[] = new CustomField([
            'name' => $name,
            'type' => $type,
            'enum_values' => $enumValues,
            'nullable' => $nullable,
            'read_only' => $readOnly,
            'has_default' => $hasDefault,
        ]);

        return $this;
    }

    /** Fields identifying a record (default `id`); composite keys arrive as `a|b` in record ids. */
    public function primaryKey(string ...$fields): self
    {
        $this->primaryKey = array_values($fields);

        return $this;
    }

    /**
     * A relation to another collection (custom or database one).
     *
     * @param  'belongsTo'|'hasMany'|'hasOne'  $kind
     * @param  array<array-key, string>|string  $foreignKey
     * @param  array<array-key, string>|string  $targetKey
     */
    public function relation(string $name, string $kind, array|string $foreignKey, string $target, array|string $targetKey = 'id'): self
    {
        $this->relations[] = new RelationHint($this->name, $name, $kind, array_values((array) $foreignKey), $target, array_values((array) $targetKey));

        return $this;
    }

    /**
     * What the list handler does itself. The agent evaluates everything else in memory, over at
     * most 2,000 records, and always checks the records you return against the caller's
     * permissions.
     *
     * @param  list<string>  $filterOperators  MongoDB operators without `$`: eq, ne, gt, gte, lt, lte, in, nin, exists, regex, like, ilike, or, nor.
     */
    public function capabilities(array $filterOperators = [], bool $sort = false, bool $count = false): self
    {
        $this->filterOperators = $filterOperators;
        $this->sort = $sort;
        $this->count = $count;

        return $this;
    }

    /** Every read and write is forwarded to your handlers (the default). */
    public function translation(): self
    {
        $this->strategy = 'translation';

        return $this;
    }

    /**
     * The agent copies the collection with the `listChanges` handler and serves reads from its
     * copy, pulling changes every `$intervalSeconds` (0: only when asked, see
     * `Aviato::refreshReplica()`).
     */
    public function replication(int $intervalSeconds = 0): self
    {
        $this->strategy = 'replication';
        $this->replicationIntervalSeconds = $intervalSeconds;

        return $this;
    }
}
