<?php

namespace Aviato\Laravel\Definitions;

use Aviato\Laravel\Enums\HookOperation;
use Aviato\Laravel\Enums\HookTiming;
use Closure;

/**
 * Runs before or after an operation on a collection.
 *
 *     Aviato::hook('customers', 'before', 'update')
 *         ->run(fn (HookContext $context) => $context->values['plan'] === 'enterprise'
 *             ? $context->reject('Enterprise plans are set by sales')
 *             : $context->proceed());
 */
final class HookDefinition
{
    public ?Closure $run = null;

    public function __construct(
        public readonly string $collection,
        public readonly HookTiming $timing,
        public readonly HookOperation $operation,
    ) {}

    /**
     * The handler receives a HookContext and returns a HookOutcome, an array like
     * `['reject' => 'message']` or `['values' => [...]]`, or null to let the operation through.
     *
     * @param  callable|class-string  $handler
     */
    public function run(callable|string $handler): self
    {
        $this->run = Handler::wrap($handler);

        return $this;
    }
}
