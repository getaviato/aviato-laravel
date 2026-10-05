<?php

namespace Aviato\Laravel\Context;

use Aviato\Laravel\Data\DataClient;
use Aviato\Laravel\Enums\HookOperation;
use Aviato\Laravel\Enums\HookTiming;
use Closure;

/** Passed to a hook's `run` handler. */
final class HookContext extends Context
{
    /**
     * @param  Closure(Caller): DataClient  $dataClientFactory
     * @param  list<string>  $recordIds  Record ids concerned.
     * @param  array<string, mixed>|null  $values  The values being written (create and update).
     */
    public function __construct(
        Caller $caller,
        Closure $dataClientFactory,
        public readonly string $collection,
        public readonly HookTiming $timing,
        public readonly HookOperation $operation,
        public readonly array $recordIds = [],
        public readonly ?array $values = null,
        /** The action name for action hooks. */
        public readonly string $action = '',
    ) {
        parent::__construct($caller, $dataClientFactory);
    }

    /** Before hooks only: rejects the operation with this message. */
    public function reject(string $message): HookOutcome
    {
        return HookOutcome::reject($message);
    }

    /** @param array<string, mixed>|null $values Replacement values (before create and update), null to keep them. */
    public function proceed(?array $values = null): HookOutcome
    {
        return HookOutcome::proceed($values);
    }
}
