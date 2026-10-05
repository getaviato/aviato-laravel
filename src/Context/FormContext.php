<?php

namespace Aviato\Laravel\Context;

use Aviato\Laravel\Data\DataClient;
use Closure;

/** Passed to an action's `resolveForm` handler when the form opens and after each change. */
final class FormContext extends TargetContext
{
    /**
     * @param  Closure(Caller): DataClient  $dataClientFactory
     * @param  list<string>  $recordIds
     * @param  array<string, mixed>|null  $filter
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        Caller $caller,
        Closure $dataClientFactory,
        string $collection,
        array $recordIds = [],
        ?array $filter = null,
        string $segment = '',
        string $search = '',
        array $values = [],
        /** The field that just changed, empty when the form opens. */
        public readonly string $changedField = '',
    ) {
        parent::__construct($caller, $dataClientFactory, $collection, $recordIds, $filter, $segment, $search, $values);
    }

    /**
     * @param  array<string, mixed>|null  $values  Values to set.
     * @param  array<string, mixed>|null  $schema  Replacement JSON Schema.
     * @param  array<string, mixed>|null  $uiSchema  Replacement UI schema.
     */
    public function resolve(?array $values = null, ?array $schema = null, ?array $uiSchema = null): FormResolution
    {
        return new FormResolution($schema, $uiSchema, $values);
    }
}
