<?php

use Aviato\Laravel\Plugin;
use Aviato\Laravel\Support\Structs;
use Aviato\Plugin\V1\Manifest;

it('preserves the shared collection layout in the manifest wire format', function () {
    $layout = json_decode(file_get_contents(__DIR__.'/../Fixtures/layout-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $plugin = app(Plugin::class);
    $plugin->layout('customers', $layout);
    $decoded = new Manifest;
    $decoded->mergeFromJsonString($plugin->manifest()->serializeToJsonString());
    expect(count($decoded->getLayouts()))->toBe(1);
    expect($decoded->getLayouts()[0]->getCollection())->toBe('customers');
    expect(Structs::toArray($decoded->getLayouts()[0]->getLayout()))->toEqual($layout);

    $plugin->layout('customers', ['label' => 'Clients']);
    expect(count($plugin->manifest()->getLayouts()))->toBe(1);
    expect(Structs::toArray($plugin->manifest()->getLayouts()[0]->getLayout()))->toBe(['label' => 'Clients']);
});
