<?php

namespace Aviato\Laravel\Support;

use BackedEnum;
use DateTimeInterface;
use Google\Protobuf\ListValue;
use Google\Protobuf\NullValue;
use Google\Protobuf\Struct;
use Google\Protobuf\Value;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;
use UnitEnum;

/**
 * Converts between google.protobuf.Struct / Value and plain PHP values.
 *
 * PHP arrays cannot tell an empty object from an empty list: an empty array nested in a Struct
 * becomes an empty list, pass `new stdClass` (or `(object) []`) for an empty object. Top-level
 * Structs are always objects.
 */
final class Structs
{
    /** @return array<string, mixed>|null */
    public static function toArray(?Struct $struct): ?array
    {
        if ($struct === null) {
            return null;
        }
        $result = [];
        foreach ($struct->getFields() as $key => $value) {
            /** @var Value $value */
            $result[(string) $key] = self::fromValue($value);
        }

        return $result;
    }

    public static function fromArray(mixed $value): Struct
    {
        $value = self::normalize($value);
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Expected an array or object, got '.get_debug_type($value).'.');
        }
        $fields = [];
        foreach ($value as $key => $inner) {
            $fields[(string) $key] = self::toValue($inner);
        }

        return new Struct(['fields' => $fields]);
    }

    public static function fromValue(Value $value): mixed
    {
        return match ($value->getKind()) {
            'number_value' => self::number($value->getNumberValue()),
            'string_value' => $value->getStringValue(),
            'bool_value' => $value->getBoolValue(),
            'struct_value' => self::toArray($value->getStructValue()),
            'list_value' => array_map(
                fn (Value $inner): mixed => self::fromValue($inner),
                iterator_to_array($value->getListValue()?->getValues() ?? []),
            ),
            default => null,
        };
    }

    public static function toValue(mixed $value): Value
    {
        $value = self::normalize($value);

        return match (true) {
            $value === null => new Value(['null_value' => NullValue::NULL_VALUE]),
            is_bool($value) => new Value(['bool_value' => $value]),
            is_int($value), is_float($value) => new Value(['number_value' => (float) $value]),
            is_string($value) => new Value(['string_value' => $value]),
            $value instanceof stdClass => new Value(['struct_value' => self::fromArray($value)]),
            is_array($value) && ($value === [] || array_is_list($value)) => new Value(['list_value' => new ListValue([
                'values' => array_map(fn (mixed $inner): Value => self::toValue($inner), $value),
            ])]),
            is_array($value) => new Value(['struct_value' => self::fromArray($value)]),
            default => throw new InvalidArgumentException('Cannot convert '.get_debug_type($value).' to a JSON value.'),
        };
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Arrayable => $value->toArray(),
            $value instanceof JsonSerializable => self::normalize($value->jsonSerialize()),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339_EXTENDED),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            default => $value,
        };
    }

    /** JSON numbers without a fraction come back as integers, like json_decode. */
    private static function number(float $number): int|float
    {
        if (floor($number) === $number && abs($number) <= 2 ** 53) {
            return (int) $number;
        }

        return $number;
    }
}
