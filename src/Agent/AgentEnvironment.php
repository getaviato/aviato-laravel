<?php

namespace Aviato\Laravel\Agent;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** The environment variables `php artisan aviato:serve` starts the agent with. */
final class AgentEnvironment
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array<string, string>
     *
     * @throws InvalidArgumentException when a required setting is missing.
     */
    public function variables(): array
    {
        $agent = (array) $this->config->get('aviato.agent', []);
        $connectionName = $this->string($agent['connection'] ?? null) ?? $this->string($this->config->get('database.default')) ?? '';
        $connection = (array) $this->config->get("database.connections.{$connectionName}", []);

        $variables = [
            'AVIATO_CONTROL_PLANE_URL' => $this->string($agent['control_plane_url'] ?? null) ?? throw new InvalidArgumentException('Set AVIATO_CONTROL_PLANE_URL (aviato.agent.control_plane_url).'),
            'AVIATO_AGENT_TOKEN' => $this->string($agent['token'] ?? null) ?? throw new InvalidArgumentException('Set AVIATO_AGENT_TOKEN (aviato.agent.token).'),
            'AVIATO_DATABASE_URL' => $this->string($agent['database_url'] ?? null) ?? DatabaseUrl::fromConnection($connection),
            'AVIATO_DATA_DIR' => $this->string($agent['data_dir'] ?? null) ?? storage_path('aviato/data'),
            'AVIATO_PLUGIN_URL' => $this->pluginUrl(),
            'AVIATO_PLUGIN_SECRET' => $this->string($this->config->get('aviato.secret')) ?? throw new InvalidArgumentException('Set AVIATO_PLUGIN_SECRET (aviato.secret).'),
        ];
        $schema = DatabaseUrl::schema($connection);
        if ($schema !== null && $this->string($agent['database_url'] ?? null) === null) {
            $variables['AVIATO_DB_SCHEMA'] = $schema;
        }
        foreach ((array) ($agent['env'] ?? []) as $name => $value) {
            if (is_scalar($value)) {
                $variables[(string) $name] = (string) $value;
            }
        }

        return $variables;
    }

    public function pluginUrl(): string
    {
        return $this->string($this->config->get('aviato.plugin_url'))
            ?? rtrim($this->string($this->config->get('app.url')) ?? 'http://localhost', '/').'/'.trim((string) $this->config->get('aviato.base_path', 'aviato'), '/');
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
