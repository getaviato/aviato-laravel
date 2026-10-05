<?php

namespace Aviato\Laravel\Definitions;

use Closure;

/**
 * A segment computed by your code.
 *
 *     Aviato::segment('customers', 'vip')->resolve(fn () => ['filter' => ['plan' => 'team']]);
 */
final class SegmentDefinition
{
    public ?Closure $resolve = null;

    public function __construct(public readonly string $collection, public readonly string $name) {}

    /**
     * The handler receives a Context and returns `['filter' => [...]]` (preferred, MongoDB
     * query syntax) or `['ids' => [...]]`.
     *
     * @param  callable|class-string  $handler
     */
    public function resolve(callable|string $handler): self
    {
        $this->resolve = Handler::wrap($handler);

        return $this;
    }
}
