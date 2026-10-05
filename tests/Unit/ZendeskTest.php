<?php

use Aviato\Laravel\Integrations\RecordBinding;
use Aviato\Laravel\Integrations\ZendeskPlugin;
use Aviato\Laravel\Plugin;

it('serializes typed Zendesk resources and snapshots tenant configuration', function () {
    $binding = new RecordBinding('zendesk_id', 'account');
    $integration = (new ZendeskPlugin('northstar', 'ZENDESK_TOKEN', true))
        ->user('customers', 'user', $binding)
        ->tickets('customers', 'tickets', $binding)
        ->ticket('issues', 'ticket', new RecordBinding('ticket_id'));
    $host = app(Plugin::class)->use($integration);
    $widgets = $host->manifest()->getWidgets();
    expect(count($widgets))->toBe(3);
    foreach (['user', 'tickets', 'ticket'] as $index => $resource) {
        expect($widgets[$index]->getResource())->toBe($resource);
        expect($widgets[$index]->getProvider())->toBe('zendesk');
        expect($widgets[$index]->getAccount())->toBe('northstar');
        expect($widgets[$index]->getCredentialEnv())->toBe('ZENDESK_TOKEN');
        expect($widgets[$index]->getReadOnly())->toBeTrue();
    }
    expect($widgets[0]->getBindingRelation())->toBe('account');
    expect($widgets[2]->getBindingField())->toBe('ticket_id');
    $widgets[0]->setAccount('mutated');
    expect($host->manifest()->getWidgets()[0]->getAccount())->toBe('northstar');
    $integration->tickets('customers', 'user', $binding);
    expect($host->manifest()->getWidgets()[0]->getResource())->toBe('user');
    $host->use($integration);
    expect($host->manifest()->getWidgets()[0]->getResource())->toBe('tickets');
});

it('rejects invalid Zendesk subdomains', function (string $subdomain) {
    expect(fn () => new ZendeskPlugin($subdomain, 'ZENDESK_TOKEN', true))->toThrow(InvalidArgumentException::class);
})->with(['', 'https://northstar.zendesk.com', 'northstar.zendesk.com', 'host@evil.test', '../evil', '-bad', 'bad-', "bad\n", str_repeat('a', 64)]);

it('rejects credentials and write-enabled declarations', function () {
    expect(fn () => new ZendeskPlugin('northstar', 'actual-token', true))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ZendeskPlugin('northstar', 'ZENDESK_TOKEN', false))->toThrow(InvalidArgumentException::class);
});

it('preserves valid Zendesk widgets after invalid replacements', function () {
    $integration = (new ZendeskPlugin('northstar', 'ZENDESK_TOKEN', true))->user('customers', 'support', new RecordBinding('id'));
    expect(fn () => $integration->tickets('customers', 'support', new RecordBinding('__proto__')))->toThrow(InvalidArgumentException::class);
    expect($integration->widgets()[0]->resource)->toBe('user');
});
