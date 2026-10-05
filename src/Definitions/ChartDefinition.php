<?php

namespace Aviato\Laravel\Definitions;

use Closure;

/**
 * A chart computed by your code, as a Vega-Lite specification with its data inlined.
 *
 *     Aviato::chart('customersByPlan', 'customers')->compute(fn (?array $filter) => ['mark' => 'bar', ...]);
 */
final class ChartDefinition
{
    public ?Closure $compute = null;

    public function __construct(public readonly string $name, public readonly string $collection) {}

    /**
     * The handler receives the current filter (or null) and a Context and returns the
     * Vega-Lite specification.
     *
     * @param  callable|class-string  $handler
     */
    public function compute(callable|string $handler): self
    {
        $this->compute = Handler::wrap($handler);

        return $this;
    }
}
