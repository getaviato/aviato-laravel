<?php

namespace Aviato\Laravel\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelInspector;

/**
 * Exposes the relation discovery of Laravel's ModelInspector (the engine behind
 * `php artisan model:show`) without its column inspection, which needs a database connection.
 */
final class RelationInspector extends ModelInspector
{
    /** @return list<array{name: string, type: string, related: class-string<Model>}> */
    public function relationsOf(Model $model): array
    {
        /** @var list<array{name: string, type: string, related: class-string<Model>}> */
        return $this->getRelations($model)->all();
    }
}
