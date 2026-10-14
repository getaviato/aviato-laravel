<?php

namespace Aviato\Laravel\Definitions;

use Closure;

/**
 * Custom search on a collection, turning the query into a filter.
 *
 *     Aviato::search('customers')->resolve(fn (string $query) => ['name' => ['$ilike' => "%{$query}%"]]);
 */
final class SearchDefinition
{
    public bool $replace = false;

    public ?Closure $resolve = null;

    public function __construct(public readonly string $collection) {}

    /** Replaces the connector's search entirely instead of adding to it. */
    public function replace(bool $replace = true): self
    {
        $this->replace = $replace;

        return $this;
    }

    /**
     * The handler receives the query and a Context and returns a filter (MongoDB query syntax).
     *
     * @param  callable|class-string  $handler
     */
    public function resolve(callable|string $handler): self
    {
        $this->resolve = Handler::wrap($handler);

        return $this;
    }
}
