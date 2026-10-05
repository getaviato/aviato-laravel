<?php

namespace Aviato\Laravel\Agent;

use RuntimeException;

/** The operating system and architecture names used by the agent's release assets. */
final readonly class Platform
{
    public function __construct(public string $os, public string $arch) {}

    public static function current(): self
    {
        return self::from(PHP_OS_FAMILY, php_uname('m'));
    }

    public static function from(string $osFamily, string $machine): self
    {
        $os = match ($osFamily) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            'Windows' => 'windows',
            default => throw new RuntimeException("The Aviato agent is not available for {$osFamily}."),
        };
        $arch = match (strtolower($machine)) {
            'x86_64', 'amd64', 'x64' => 'x64',
            'arm64', 'aarch64' => 'arm64',
            default => throw new RuntimeException("The Aviato agent is not available for the {$machine} architecture."),
        };

        return new self($os, $arch);
    }

    public function extension(): string
    {
        return $this->os === 'windows' ? '.exe' : '';
    }
}
