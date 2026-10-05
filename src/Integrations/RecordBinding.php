<?php

namespace Aviato\Laravel\Integrations;

/** A column on this record or on one belongsTo relation. */
final readonly class RecordBinding
{
    public function __construct(public string $field, public string $relation = '') {}
}
