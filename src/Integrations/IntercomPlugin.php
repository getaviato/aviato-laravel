<?php

namespace Aviato\Laravel\Integrations;

use InvalidArgumentException;

/**
 * Connector-local access token integration. The connector checks that the token works for the configured region
 * (GET /me). Intercom offers no API to confirm a token is read-only, so that depends on the Intercom app's permissions
 * (see docs.getaviato.com/integrations/intercom).
 */
final class IntercomPlugin implements IntegrationPlugin
{
    /** @var array<string, IntegrationWidget> */
    private array $definitions = [];

    /** @param string $region Exactly "us", "eu" or "au", never a URL or host. */
    public function __construct(
        private readonly string $region,
        private readonly string $credentialEnv,
        private readonly bool $readOnly,
    ) {
        if (! $readOnly || ! preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $credentialEnv)) {
            throw new InvalidArgumentException('Intercom requires a read-only access token environment variable');
        }
        if (! in_array($region, ['us', 'eu', 'au'], true)) {
            throw new InvalidArgumentException('Intercom requires region us, eu or au, not a URL');
        }
    }

    /** Set exactly one of $externalId (matched on Intercom external_id) or $contactId (the Intercom contact ID). */
    public function contact(string $collection, string $name, ?RecordBinding $externalId = null, ?RecordBinding $contactId = null, string $title = ''): self
    {
        if ($externalId !== null && $contactId === null) {
            return $this->add('external-contact', $collection, $name, $externalId, $title);
        }
        if ($contactId !== null && $externalId === null) {
            return $this->add('contact', $collection, $name, $contactId, $title);
        }

        throw new InvalidArgumentException('Intercom contact requires exactly one of externalId or contactId');
    }

    private function add(string $resource, string $collection, string $name, RecordBinding $binding, string $title): self
    {
        $this->definitions[$collection."\0".$name] = new IntegrationWidget(
            $collection, $name, 'intercom', $resource, $binding, $this->credentialEnv, $this->readOnly, $title, $this->region,
        );

        return $this;
    }

    public function widgets(): array
    {
        return array_values($this->definitions);
    }
}
