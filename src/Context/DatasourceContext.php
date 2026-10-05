<?php

namespace Aviato\Laravel\Context;

/** What every datasource handler receives about the call. */
final readonly class DatasourceContext
{
    public function __construct(
        /** The custom collection, as you declared it. */
        public string $collection,
        /** Who the agent acts for; null for the agent's own calls (replication). */
        public ?Caller $caller = null,
    ) {}
}
