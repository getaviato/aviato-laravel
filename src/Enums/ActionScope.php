<?php

namespace Aviato\Laravel\Enums;

use Aviato\Plugin\V1\ActionScope as ProtoActionScope;

enum ActionScope: string
{
    /** One record. */
    case Single = 'single';
    /** A selection of records. */
    case Bulk = 'bulk';
    /** The collection, with the current filters, segment and search. */
    case Global = 'global';

    public function toProto(): int
    {
        return match ($this) {
            self::Single => ProtoActionScope::ACTION_SCOPE_SINGLE,
            self::Bulk => ProtoActionScope::ACTION_SCOPE_BULK,
            self::Global => ProtoActionScope::ACTION_SCOPE_GLOBAL,
        };
    }
}
