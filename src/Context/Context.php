<?php

namespace Aviato\Laravel\Context;

use Aviato\Laravel\Data\DataClient;
use Closure;

/** What every handler receives: the caller and the connector's Data API. */
class Context
{
    private ?DataClient $data = null;

    /** @param Closure(Caller): DataClient $dataClientFactory */
    public function __construct(public readonly Caller $caller, private readonly Closure $dataClientFactory) {}

    /** Reads and writes records through the connector, under the caller's permissions. */
    public function data(): DataClient
    {
        return $this->data ??= ($this->dataClientFactory)($this->caller);
    }
}
