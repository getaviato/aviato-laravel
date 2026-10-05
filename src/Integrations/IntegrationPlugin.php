<?php

namespace Aviato\Laravel\Integrations;

/** A customer-side provider integration. The host snapshots its declarations on use(). */
interface IntegrationPlugin
{
    /** @return list<IntegrationWidget> */
    public function widgets(): array;
}
