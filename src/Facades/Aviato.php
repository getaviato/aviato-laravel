<?php

namespace Aviato\Laravel\Facades;

use Aviato\Laravel\Plugin;
use Aviato\Laravel\Testing\PluginFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Aviato\Laravel\Plugin use(\Aviato\Laravel\Integrations\IntegrationPlugin $integration)
 * @method static \Aviato\Laravel\Plugin summary(string $collection, array<string, mixed> $document)
 * @method static \Aviato\Laravel\Definitions\ActionDefinition action(string $collection, string $name)
 * @method static \Aviato\Laravel\Definitions\ComputedFieldDefinition computedField(string $collection, string $name)
 * @method static \Aviato\Laravel\Definitions\HookDefinition hook(string $collection, \Aviato\Laravel\Enums\HookTiming|string $timing, \Aviato\Laravel\Enums\HookOperation|string $operation)
 * @method static \Aviato\Laravel\Definitions\SegmentDefinition segment(string $collection, string $name)
 * @method static \Aviato\Laravel\Definitions\SearchDefinition search(string $collection)
 * @method static \Aviato\Laravel\Definitions\WriteOverrideDefinition writeOverride(string $collection, string $field)
 * @method static \Aviato\Laravel\Definitions\ChartDefinition chart(string $name, string $collection)
 * @method static \Aviato\Laravel\Definitions\DatasourceDefinition datasource()
 * @method static array{collection: string, upserted: int, deleted: int, cursor: string} refreshReplica(string $collection, string $dataUrl)
 * @method static Plugin relation(string $collection, string $name, string $kind, array<int, string>|string $foreignKey, string $target, array<int, string>|string $targetKey = 'id')
 * @method static list<\Aviato\Laravel\Definitions\RelationHint> relationHints()
 * @method static \Aviato\Plugin\V1\Manifest manifest()
 *
 * @see Plugin
 */
final class Aviato extends Facade
{
    /**
     * Lets you call your plugin's handlers in tests, without an agent, with a fake caller and an
     * in-memory Data API.
     */
    public static function fake(): PluginFake
    {
        $plugin = self::getFacadeRoot();

        return new PluginFake($plugin instanceof Plugin ? $plugin : app(Plugin::class));
    }

    protected static function getFacadeAccessor(): string
    {
        return Plugin::class;
    }
}
