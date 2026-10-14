<?php

namespace Aviato\Laravel;

/** Native UI v1 builders. Documents are validated by the connector and dashboard. */
final class UI
{
    /** @return array{'$bind': string} */
    public static function bind(string $path): array
    {
        return ['$bind' => $path];
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    public static function node(string $type, array $props = [], array $children = []): array
    {
        $node = ['type' => $type];
        if ($props !== []) {
            $node['props'] = (object) $props;
        }
        if ($children !== []) {
            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $options  Optional state and reusable components.
     * @return array<string, mixed>
     */
    public static function document(array $root, array $options = []): array
    {
        return ['version' => 1, 'root' => $root] + $options;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function form(array $document): array
    {
        return ['type' => 'AviatoUI', 'document' => $document];
    }
}
