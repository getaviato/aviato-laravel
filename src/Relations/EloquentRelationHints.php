<?php

namespace Aviato\Laravel\Relations;

use Aviato\Laravel\Definitions\RelationHint;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Turns the belongsTo / hasOne / hasMany relations declared on Eloquent models into relation
 * hints. Polymorphic and many-to-many relations are skipped: they have no single foreign key.
 */
final class EloquentRelationHints
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param  list<class-string<Model>>|null  $models  Null discovers the models in app/Models.
     * @return list<RelationHint>
     */
    public function hints(?array $models = null): array
    {
        $inspector = new RelationInspector($this->app);
        $hints = [];
        foreach ($models ?? $this->discover() as $class) {
            try {
                $model = $this->app->make($class);
                if (! $model instanceof Model) {
                    continue;
                }
                foreach ($inspector->relationsOf($model) as $relation) {
                    $hint = $this->hint($model, $relation['name']);
                    if ($hint !== null) {
                        $hints[] = $hint;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $hints;
    }

    private function hint(Model $model, string $name): ?RelationHint
    {
        $relation = $model->{$name}();
        $collection = $this->table($model);
        $target = $this->table($relation->getRelated());
        if ($relation instanceof BelongsTo && ! $relation instanceof MorphTo) {
            return new RelationHint($collection, $name, 'belongsTo', [$relation->getForeignKeyName()], $target, [$relation->getOwnerKeyName()]);
        }
        if ($relation instanceof HasOneOrMany && ! $relation instanceof MorphOneOrMany) {
            $kind = $relation instanceof HasOne ? 'hasOne' : 'hasMany';

            return new RelationHint($collection, $name, $kind, [$relation->getForeignKeyName()], $target, [$relation->getLocalKeyName()]);
        }

        return null;
    }

    private function table(Model $model): string
    {
        return $model->getConnection()->getTablePrefix().$model->getTable();
    }

    /** @return list<class-string<Model>> */
    private function discover(): array
    {
        $directory = $this->app->path('Models');
        if (! is_dir($directory)) {
            return [];
        }
        $namespace = $this->app->getNamespace().'Models\\';
        $models = [];
        foreach (File::allFiles($directory) as $file) {
            $class = $namespace.str_replace('/', '\\', Str::beforeLast($file->getRelativePathname(), '.php'));
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }
        sort($models);

        return $models;
    }
}
