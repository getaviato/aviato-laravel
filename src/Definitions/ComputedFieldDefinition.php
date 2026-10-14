<?php

namespace Aviato\Laravel\Definitions;

use Closure;

/**
 * A field computed by your code for a page of records at once.
 *
 *     Aviato::computedField('customers', 'fullName')
 *         ->dependsOn(['first_name', 'last_name'])
 *         ->compute(fn (array $records) => array_map(fn ($r) => "{$r['first_name']} {$r['last_name']}", $records));
 */
final class ComputedFieldDefinition
{
    /** A protocol field type: string, number, boolean, date, dateonly, json, enum, … */
    public string $type = 'string';

    /** @var list<string> */
    public array $dependencies = [];

    /** @var list<string> */
    public array $enumValues = [];

    public bool $emulateFilterSort = false;

    public ?Closure $compute = null;

    public function __construct(public readonly string $collection, public readonly string $name) {}

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Fields loaded on each record before computing.
     *
     * @param  array<array-key, string>|string  $fields
     */
    public function dependsOn(array|string ...$fields): self
    {
        $this->dependencies = array_merge(...array_map(fn (array|string $field): array => array_values((array) $field), $fields));

        return $this;
    }

    /** @param array<array-key, string> $values */
    public function enumValues(array $values): self
    {
        $this->type = 'enum';
        $this->enumValues = array_values($values);

        return $this;
    }

    /** Lets the connector sort and filter on the field by computing it in memory (bounded). */
    public function emulateFilterSort(bool $emulate = true): self
    {
        $this->emulateFilterSort = $emulate;

        return $this;
    }

    /**
     * The handler receives the records (restricted to the dependencies and primary keys) and a
     * Context, and returns one value per record in the same order.
     *
     * @param  callable|class-string  $handler
     */
    public function compute(callable|string $handler): self
    {
        $this->compute = Handler::wrap($handler);

        return $this;
    }
}
