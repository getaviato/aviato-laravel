<?php

namespace Aviato\Laravel\Enums;

use Aviato\Plugin\V1\HookOperation as ProtoHookOperation;

enum HookOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case List = 'list';
    case Action = 'action';

    public function toProto(): int
    {
        return match ($this) {
            self::Create => ProtoHookOperation::HOOK_OPERATION_CREATE,
            self::Update => ProtoHookOperation::HOOK_OPERATION_UPDATE,
            self::Delete => ProtoHookOperation::HOOK_OPERATION_DELETE,
            self::List => ProtoHookOperation::HOOK_OPERATION_LIST,
            self::Action => ProtoHookOperation::HOOK_OPERATION_ACTION,
        };
    }

    public static function fromProto(int $value): ?self
    {
        return match ($value) {
            ProtoHookOperation::HOOK_OPERATION_CREATE => self::Create,
            ProtoHookOperation::HOOK_OPERATION_UPDATE => self::Update,
            ProtoHookOperation::HOOK_OPERATION_DELETE => self::Delete,
            ProtoHookOperation::HOOK_OPERATION_LIST => self::List,
            ProtoHookOperation::HOOK_OPERATION_ACTION => self::Action,
            default => null,
        };
    }
}
