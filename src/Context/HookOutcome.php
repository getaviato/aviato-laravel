<?php

namespace Aviato\Laravel\Context;

/** What a hook decided. Only before hooks can reject or replace values. */
final readonly class HookOutcome
{
    /** @param array<string, mixed>|null $values */
    public function __construct(public ?string $rejectMessage = null, public ?array $values = null) {}

    public static function reject(string $message): self
    {
        return new self(rejectMessage: $message);
    }

    /** @param array<string, mixed>|null $values Replacement values, null to keep them. */
    public static function proceed(?array $values = null): self
    {
        return new self(values: $values);
    }

    public function rejected(): bool
    {
        return $this->rejectMessage !== null && $this->rejectMessage !== '';
    }
}
