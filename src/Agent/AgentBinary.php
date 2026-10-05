<?php

namespace Aviato\Laravel\Agent;

/** Where the agent binary lives and where it is downloaded from. */
final readonly class AgentBinary
{
    public function __construct(
        public string $path,
        private string $downloadUrl,
        private ?string $checksumUrl,
        public string $version,
        public Platform $platform,
    ) {}

    /** @param array<string, mixed> $config The `aviato.agent` configuration. */
    public static function fromConfig(array $config, ?Platform $platform = null): self
    {
        $platform ??= Platform::current();
        $path = is_string($config['path'] ?? null) && $config['path'] !== ''
            ? $config['path']
            : storage_path('aviato/aviato-agent'.$platform->extension());

        return new self(
            $path,
            is_string($config['download_url'] ?? null) ? $config['download_url'] : '',
            is_string($config['checksum_url'] ?? null) && $config['checksum_url'] !== '' ? $config['checksum_url'] : null,
            is_scalar($config['version'] ?? null) ? (string) $config['version'] : 'latest',
            $platform,
        );
    }

    public function downloadUrl(): string
    {
        return $this->expand($this->downloadUrl);
    }

    public function checksumUrl(): ?string
    {
        return $this->checksumUrl !== null ? str_replace('{url}', $this->downloadUrl(), $this->expand($this->checksumUrl)) : null;
    }

    public function installed(): bool
    {
        return is_file($this->path);
    }

    private function expand(string $template): string
    {
        return strtr($template, [
            '{version}' => $this->version,
            '{os}' => $this->platform->os,
            '{arch}' => $this->platform->arch,
            '{ext}' => $this->platform->extension(),
        ]);
    }
}
