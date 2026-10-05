<?php

namespace Aviato\Laravel\Integrations;

use InvalidArgumentException;

final class StripePlugin implements IntegrationPlugin
{
    /** @var array<string, IntegrationWidget> */
    private array $definitions = [];

    /** readOnly confirms Read/None permissions configured by the operator in Stripe. */
    public function __construct(private readonly string $credentialEnv, private readonly bool $readOnly)
    {
        if (! $readOnly || ! preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $credentialEnv)) {
            throw new InvalidArgumentException('Stripe requires a read-only credential environment variable');
        }
    }

    public function customer(string $collection, string $name, RecordBinding $customerId, string $title = ''): self
    {
        return $this->add('customer', $collection, $name, $customerId, $title);
    }

    public function subscriptions(string $collection, string $name, RecordBinding $customerId, string $title = ''): self
    {
        return $this->add('subscriptions', $collection, $name, $customerId, $title);
    }

    public function invoices(string $collection, string $name, RecordBinding $customerId, string $title = ''): self
    {
        return $this->add('invoices', $collection, $name, $customerId, $title);
    }

    public function subscription(string $collection, string $name, RecordBinding $subscriptionId, string $title = ''): self
    {
        return $this->add('subscription', $collection, $name, $subscriptionId, $title);
    }

    public function invoice(string $collection, string $name, RecordBinding $invoiceId, string $title = ''): self
    {
        return $this->add('invoice', $collection, $name, $invoiceId, $title);
    }

    private function add(string $resource, string $collection, string $name, RecordBinding $binding, string $title): self
    {
        $this->definitions[$collection."\0".$name] = new IntegrationWidget(
            $collection, $name, 'stripe', $resource, $binding, $this->credentialEnv, $this->readOnly, $title,
        );

        return $this;
    }

    public function widgets(): array
    {
        return array_values($this->definitions);
    }
}
