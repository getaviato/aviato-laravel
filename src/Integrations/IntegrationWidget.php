<?php

namespace Aviato\Laravel\Integrations;

use InvalidArgumentException;

/** The common metadata contract for integration authors, with no credential values. */
final readonly class IntegrationWidget
{
    public function __construct(
        public string $collection,
        public string $name,
        public string $provider,
        public string $resource,
        public RecordBinding $binding,
        public string $credentialEnv,
        public bool $readOnly,
        public string $title = '',
        public string $account = '',
    ) {
        if (! $readOnly || ! preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $credentialEnv)) {
            throw new InvalidArgumentException('Widgets require read-only credentials referenced by environment variable name');
        }
        foreach ([$collection, $name, $binding->field] as $value) {
            if ($value === '' || strlen($value) > 128 || in_array($value, ['__proto__', 'constructor', 'prototype'], true)) {
                throw new InvalidArgumentException('Invalid widget collection, name or binding field');
            }
        }
        if (strlen($account) > 128 || strlen($title) > 160 || strlen($binding->relation) > 128 || ! preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $provider) || ! preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $resource)) {
            throw new InvalidArgumentException('Invalid widget metadata');
        }
    }
}
