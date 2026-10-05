<?php

namespace Aviato\Laravel\Definitions;

use Aviato\Plugin\V1\RelationHint as ProtoRelationHint;

/**
 * A relation the agent cannot infer from foreign keys alone. `foreignKey` always holds the
 * foreign key columns and `targetKey` the columns they reference, whichever side the relation
 * is declared on.
 */
final readonly class RelationHint
{
    /**
     * @param  'belongsTo'|'hasMany'|'hasOne'  $kind
     * @param  list<string>  $foreignKey
     * @param  list<string>  $targetKey
     */
    public function __construct(
        public string $collection,
        public string $name,
        public string $kind,
        public array $foreignKey,
        public string $target,
        public array $targetKey,
    ) {}

    public function toProto(): ProtoRelationHint
    {
        return new ProtoRelationHint([
            'collection' => $this->collection,
            'name' => $this->name,
            'kind' => $this->kind,
            'foreign_key' => $this->foreignKey,
            'target' => $this->target,
            'target_key' => $this->targetKey,
        ]);
    }

    /** @return array{collection: string, name: string, kind: string, foreignKey: list<string>, target: string, targetKey: list<string>} */
    public function toArray(): array
    {
        return [
            'collection' => $this->collection,
            'name' => $this->name,
            'kind' => $this->kind,
            'foreignKey' => $this->foreignKey,
            'target' => $this->target,
            'targetKey' => $this->targetKey,
        ];
    }
}
