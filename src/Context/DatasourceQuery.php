<?php

namespace Aviato\Laravel\Context;

/** What the agent asks a datasource's list handler for. */
final readonly class DatasourceQuery
{
    /**
     * @param  array<string, mixed>|null  $filter  MongoDB query syntax, using only the declared operators (row scopes included).
     * @param  list<array{field: string, direction: 'asc'|'desc'}>  $sort  Empty unless the collection declares `sort`.
     * @param  int|null  $limit  Null: every matching record.
     * @param  list<string>  $fields  Fields to return (empty: all); returning more is fine.
     * @param  bool  $includeTotal  Return `total` (when the collection declares `count`).
     */
    public function __construct(
        public ?array $filter = null,
        public array $sort = [],
        public ?int $limit = null,
        public int $offset = 0,
        public array $fields = [],
        public bool $includeTotal = false,
    ) {}

    /**
     * Parses the protocol's sort (`field,-other`).
     *
     * @return list<array{field: string, direction: 'asc'|'desc'}>
     */
    public static function parseSort(string $sort): array
    {
        $keys = [];
        foreach (explode(',', $sort) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $keys[] = str_starts_with($part, '-')
                ? ['field' => substr($part, 1), 'direction' => 'desc']
                : ['field' => $part, 'direction' => 'asc'];
        }

        return $keys;
    }
}
