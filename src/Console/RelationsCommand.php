<?php

namespace Aviato\Laravel\Console;

use Aviato\Laravel\Definitions\RelationHint;
use Aviato\Laravel\Plugin;
use Illuminate\Console\Command;

/** Lists the relation hints the plugin sends to the connector. */
final class RelationsCommand extends Command
{
    protected $signature = 'aviato:relations {--json : Output the hints as JSON}';

    protected $description = 'List the Eloquent relations sent to the Aviato connector as relation hints';

    public function handle(Plugin $plugin): int
    {
        $hints = $plugin->relationHints();
        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (RelationHint $hint): array => $hint->toArray(), $hints), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($hints === []) {
            $this->components->info('No relation hints. Add models to aviato.relations.models or put them in app/Models.');

            return self::SUCCESS;
        }
        $this->table(
            ['Collection', 'Relation', 'Kind', 'Foreign key', 'Target', 'Target key'],
            array_map(fn (RelationHint $hint): array => [
                $hint->collection,
                $hint->name,
                $hint->kind,
                implode(', ', $hint->foreignKey),
                $hint->target,
                implode(', ', $hint->targetKey),
            ], $hints),
        );

        return self::SUCCESS;
    }
}
