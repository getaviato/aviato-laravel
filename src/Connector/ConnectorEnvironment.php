<?php

namespace Aviato\Laravel\Connector;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** The environment variables `php artisan aviato:serve` starts the connector with. */
final class ConnectorEnvironment
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array<string, string>
     *
     * @throws InvalidArgumentException when a required setting is missing.
     */
    public function variables(): array
    {
        $connector = $this->connector();
        $variables = [
            'AVIATO_CONTROL_PLANE_URL' => $this->string($connector['control_plane_url'] ?? null) ?? throw new InvalidArgumentException('Set AVIATO_CONTROL_PLANE_URL (aviato.connector.control_plane_url).'),
            'AVIATO_CONNECTOR_TOKEN' => $this->string($connector['token'] ?? null) ?? throw new InvalidArgumentException('Set AVIATO_CONNECTOR_TOKEN (aviato.connector.token).'),
            'AVIATO_DATA_DIR' => $this->string($connector['data_dir'] ?? null) ?? storage_path('aviato/data'),
            'AVIATO_PLUGIN_URL' => $this->pluginUrl(),
            'AVIATO_PLUGIN_SECRET' => $this->string($this->config->get('aviato.secret')) ?? throw new InvalidArgumentException('Set AVIATO_PLUGIN_SECRET (aviato.secret).'),
        ];
        // Existing supplied binaries still read the original token variable.
        $variables['AVIATO_AGENT_TOKEN'] = $variables['AVIATO_CONNECTOR_TOKEN'];

        return [...$this->databaseVariables(), ...$variables, ...$this->extraVariables()];
    }

    /**
     * The database settings alone, which `php artisan aviato:types` introspects without a project
     * connection.
     *
     * @return array<string, string>
     */
    public function databaseVariables(): array
    {
        $connector = $this->connector();
        $connectionName = $this->string($connector['connection'] ?? null) ?? $this->string($this->config->get('database.default')) ?? '';
        $connection = (array) $this->config->get("database.connections.{$connectionName}", []);
        $variables = ['AVIATO_DATABASE_URL' => $this->string($connector['database_url'] ?? null) ?? DatabaseUrl::fromConnection($connection)];
        $schema = DatabaseUrl::schema($connection);
        if ($schema !== null && $this->string($connector['database_url'] ?? null) === null) {
            $variables['AVIATO_DB_SCHEMA'] = $schema;
        }

        return [...$variables, ...$this->extraVariables()];
    }

    /** @return array<string, mixed> */
    private function connector(): array
    {
        return (array) array_replace((array) $this->config->get('aviato.connector', []), (array) $this->config->get('aviato.agent', []));
    }

    /**
     * `aviato.connector.env`: extra variables, such as additional datasources.
     *
     * @return array<string, string>
     */
    private function extraVariables(): array
    {
        $variables = [];
        foreach ((array) ($this->connector()['env'] ?? []) as $name => $value) {
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
