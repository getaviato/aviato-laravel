<?php

namespace Aviato\Laravel\Definitions;

use Aviato\Laravel\Exceptions\InvalidDefinitionException;
use Closure;

/**
 * A handler is a closure, any callable, or the class name of an invokable class resolved from
 * the container (so it can use constructor injection).
 */
final class Handler
{
    /** @param callable|class-string $handler */
    public static function wrap(callable|string $handler): Closure
    {
        if (is_callable($handler)) {
            return Closure::fromCallable($handler);
        }

        return static function (mixed ...$arguments) use ($handler): mixed {
            $instance = app($handler);
            if (! is_callable($instance)) {
                throw new InvalidDefinitionException("Handler {$handler} is not invokable.");
            }

            return $instance(...$arguments);
        };
    }
}
