<?php

use Aviato\Laravel\Support\Structs;
use Illuminate\Support\Carbon;

it('round-trips JSON values', function () {
    $value = ['s' => 'x', 'i' => 3, 'f' => 1.5, 'b' => false, 'n' => null, 'list' => [1, 'a', ['k' => 'v']], 'object' => ['nested' => true]];

    expect(Structs::toArray(Structs::fromArray($value)))->toBe($value)
        ->and(Structs::fromArray($value)->serializeToJsonString())->toBe('{"s":"x","i":3,"f":1.5,"b":false,"n":null,"list":[1,"a",{"k":"v"}],"object":{"nested":true}}');
});

it('encodes empty objects and lists', function () {
    expect(Structs::fromArray([])->serializeToJsonString())->toBe('{}')
        ->and(Structs::fromArray(['list' => [], 'object' => new stdClass])->serializeToJsonString())->toBe('{"list":[],"object":{}}');
});

it('converts Laravel values', function () {
    $struct = Structs::fromArray(['at' => Carbon::parse('2026-01-02T03:04:05Z'), 'items' => collect([1, 2])]);

    expect(Structs::toArray($struct))->toBe(['at' => '2026-01-02T03:04:05.000000Z', 'items' => [1, 2]]);
});
