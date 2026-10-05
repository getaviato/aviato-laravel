<?php

namespace Aviato\Laravel\Enums;

use Aviato\Plugin\V1\HookTiming as ProtoHookTiming;

enum HookTiming: string
{
    case Before = 'before';
    case After = 'after';

    public function toProto(): int
    {
        return match ($this) {
            self::Before => ProtoHookTiming::HOOK_TIMING_BEFORE,
            self::After => ProtoHookTiming::HOOK_TIMING_AFTER,
        };
    }

    public static function fromProto(int $value): ?self
    {
        return match ($value) {
            ProtoHookTiming::HOOK_TIMING_BEFORE => self::Before,
            ProtoHookTiming::HOOK_TIMING_AFTER => self::After,
            default => null,
        };
    }
}
