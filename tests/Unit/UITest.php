<?php

use Aviato\Laravel\Plugin;
use Aviato\Laravel\Support\Structs;
use Aviato\Laravel\UI;

it('preserves the shared UI document in summary and form wire formats', function (string $fixture) {
    $document = json_decode(file_get_contents(__DIR__.'/../Fixtures/'.$fixture), true, 512, JSON_THROW_ON_ERROR);
    $plugin = app(Plugin::class);
    $plugin->summary('customers', $document);
    $plugin->action('customers', 'Example')->form(['type' => 'object'], UI::form($document));
    $manifest = $plugin->manifest();
    expect(Structs::toArray($manifest->getSummaries()[0]->getDocument()))->toBe($document);
    expect(Structs::toArray($manifest->getActions()[0]->getFormUiSchema()))->toBe(['type' => 'AviatoUI', 'document' => $document]);
    $plugin->summary('customers', UI::document(UI::node('Stack', children: [UI::node('Text', ['value' => UI::bind('/record/name')])])));
    expect(count($plugin->manifest()->getSummaries()))->toBe(1);
    expect(Structs::toArray($plugin->manifest()->getSummaries()[0]->getDocument()))->toBe([
        'version' => 1,
        'root' => ['type' => 'Stack', 'children' => [['type' => 'Text', 'props' => ['value' => ['$bind' => '/record/name']]]]],
    ]);
})->with(['ui-v1.json', 'ui-overview-v1.json']);
