<?php

namespace Aviato\Laravel\Rpc;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * Files produced by actions, downloadable once by the connector. They live in a cache store so
 * any worker of the application can serve them.
 */
final class FileStore
{
    private const PREFIX = 'aviato:file:';

    public function __construct(private readonly Repository $cache, private readonly int $ttl = 300) {}

    public function put(string $name, string $mimeType, string $content): string
    {
        $ref = (string) Str::uuid();
        $this->cache->put(self::PREFIX.$ref, ['name' => $name, 'mimeType' => $mimeType, 'content' => base64_encode($content)], $this->ttl);

        return $ref;
    }

    /**
     * Returns the file and forgets it.
     *
     * @return array{name: string, mimeType: string, content: string}|null
     */
    public function pull(string $ref): ?array
    {
        $file = $this->cache->pull(self::PREFIX.$ref);
        if (! is_array($file) || ! is_string($file['name'] ?? null) || ! is_string($file['mimeType'] ?? null) || ! is_string($file['content'] ?? null)) {
            return null;
        }
        $content = base64_decode($file['content'], true);

        return $content === false ? null : ['name' => $file['name'], 'mimeType' => $file['mimeType'], 'content' => $content];
    }
}
