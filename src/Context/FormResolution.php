<?php

namespace Aviato\Laravel\Context;

/** The dynamic parts of an action form, as returned by a `resolveForm` handler. */
final readonly class FormResolution
{
    /**
     * @param  array<string, mixed>|null  $schema  JSON Schema of the form data, null to keep the static one.
     * @param  array<string, mixed>|null  $uiSchema  JSON Forms UI schema, null to keep the static one.
     * @param  array<string, mixed>|null  $values  Values to set (defaults, recomputed values).
     */
    public function __construct(
        public ?array $schema = null,
        public ?array $uiSchema = null,
        public ?array $values = null,
    ) {}
}
