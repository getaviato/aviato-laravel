<?php

namespace Aviato\Laravel\Definitions;

use Closure;

/**
 * Custom write behaviour for a field, for example splitting a full name.
 *
 *     Aviato::writeOverride('customers', 'fullName')->write(function (mixed $value) {
 *         [$first, $last] = explode(' ', (string) $value, 2) + ['', ''];
 *         return ['first_name' => $first, 'last_name' => $last];
 *     });
 */
final class WriteOverrideDefinition
{
    public ?Closure $write = null;

    public function __construct(public readonly string $collection, public readonly string $field) {}

    /**
     * The handler receives the value, the record as known so far and a Context, and returns
     * the column values to write instead.
     *
     * @param  callable|class-string  $handler
     */
    public function write(callable|string $handler): self
    {
        $this->write = Handler::wrap($handler);

        return $this;
    }
}
