<?php

namespace Aviato\Laravel\Integrations;

use InvalidArgumentException;

/** Connector-local OAuth integration. The connector verifies read-only scopes before loading data. */
final class ZendeskPlugin implements IntegrationPlugin
{
    /** @var array<string, IntegrationWidget> */
    private array $definitions = [];

    public function __construct(
        private readonly string $subdomain,
        private readonly string $credentialEnv,
        private readonly bool $readOnly,
    ) {
        if (! $readOnly || ! preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $credentialEnv) || ! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain)) {
            throw new InvalidArgumentException('Zendesk requires a subdomain label and read-only OAuth credential environment variable');
        }
    }

    public function user(string $collection, string $name, RecordBinding $userId, string $title = ''): self
    {
        return $this->add('user', $collection, $name, $userId, $title);
    }

    public function tickets(string $collection, string $name, RecordBinding $userId, string $title = ''): self
    {
        return $this->add('tickets', $collection, $name, $userId, $title);
    }

    public function ticket(string $collection, string $name, RecordBinding $ticketId, string $title = ''): self
    {
        return $this->add('ticket', $collection, $name, $ticketId, $title);
    }

    private function add(string $resource, string $collection, string $name, RecordBinding $binding, string $title): self
    {
        $this->definitions[$collection."\0".$name] = new IntegrationWidget(
            $collection, $name, 'zendesk', $resource, $binding, $this->credentialEnv, $this->readOnly, $title, $this->subdomain,
        );

        return $this;
    }

    public function widgets(): array
    {
        return array_values($this->definitions);
    }
}
