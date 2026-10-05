<?php

namespace Aviato\Laravel\Definitions;

use Closure;

/**
 * Collections your code serves itself: an internal API, a SaaS (Stripe, HubSpot), a file. The
 * agent applies roles, row scopes, field masking and the audit trail as for its databases.
 *
 *     Aviato::datasource()
 *         ->collection('tickets', fn (CustomCollectionDefinition $collection) => $collection
 *             ->field('id', 'number', nullable: false, readOnly: true)
 *             ->field('subject', 'string', nullable: false)
 *             ->capabilities(filterOperators: ['eq'], sort: true))
 *         ->list(fn (DatasourceQuery $query, DatasourceContext $context) => ['records' => [...], 'total' => 42])
 *         ->create(fn (array $values, DatasourceContext $context) => Helpdesk::create($values));
 *
 * Every handler receives a DatasourceContext last, with the collection name and the caller.
 */
final class DatasourceDefinition
{
    /** @var list<CustomCollectionDefinition> */
    public array $collections = [];

    /** `translation` or `replication`. */
    public string $strategy = 'translation';

    public ?Closure $list = null;

    public ?Closure $get = null;

    public ?Closure $create = null;

    public ?Closure $update = null;

    public ?Closure $delete = null;

    public ?Closure $listChanges = null;

    /**
     * Declares a collection; `$define` receives its CustomCollectionDefinition.
     *
     * @param  (callable(CustomCollectionDefinition): mixed)|null  $define
     */
    public function collection(string $name, ?callable $define = null): self
    {
        $collection = new CustomCollectionDefinition($name);
        if ($define !== null) {
            $define($collection);
        }
        $this->collections[] = $collection;

        return $this;
    }

    /** Every read and write is forwarded to your handlers (the default). */
    public function translation(): self
    {
        $this->strategy = 'translation';

        return $this;
    }

    /** The agent copies the collections with `listChanges` and serves reads from its copy. */
    public function replication(): self
    {
        $this->strategy = 'replication';

        return $this;
    }

    /**
     * Required. Receives a DatasourceQuery and returns `['records' => [...], 'total' => int]`
     * (total defaults to the number of records) or a plain list of records.
     *
     * @param  callable|class-string  $handler
     */
    public function list(callable|string $handler): self
    {
        $this->list = Handler::wrap($handler);

        return $this;
    }

    /**
     * Receives the record id and returns the record, or null when there is none. Defaults to
     * looking the record up with the list handler.
     *
     * @param  callable|class-string  $handler
     */
    public function get(callable|string $handler): self
    {
        $this->get = Handler::wrap($handler);

        return $this;
    }

    /**
     * Receives the values and returns the record as stored, with its primary key.
     *
     * @param  callable|class-string  $handler
     */
    public function create(callable|string $handler): self
    {
        $this->create = Handler::wrap($handler);

        return $this;
    }

    /**
     * Receives the record id and the values and returns the record as stored.
     *
     * @param  callable|class-string  $handler
     */
    public function update(callable|string $handler): self
    {
        $this->update = Handler::wrap($handler);

        return $this;
    }

    /**
     * Receives the record id.
     *
     * @param  callable|class-string  $handler
     */
    public function delete(callable|string $handler): self
    {
        $this->delete = Handler::wrap($handler);

        return $this;
    }

    /**
     * Required by replicated collections. Receives the cursor (empty for a full copy) and returns
     * `['records' => [...], 'deletedIds' => [...], 'nextCursor' => '…', 'hasMore' => bool]`.
     *
     * @param  callable|class-string  $handler
     */
    public function listChanges(callable|string $handler): self
    {
        $this->listChanges = Handler::wrap($handler);

        return $this;
    }
}
