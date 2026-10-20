<?php

use Aviato\Laravel\Integrations\IntercomPlugin;
use Aviato\Laravel\Integrations\RecordBinding;
use Aviato\Laravel\Plugin;

it('serializes Intercom contact widgets with the region as account', function () {
    $integration = (new IntercomPlugin('eu', 'INTERCOM_TOKEN', true))
        ->contact('customers', 'by-external', externalId: new RecordBinding('id'), title: 'Intercom')
        ->contact('customers', 'by-contact', contactId: new RecordBinding('intercom_contact_id', 'account'));
    $host = app(Plugin::class)->use($integration);
    $widgets = $host->manifest()->getWidgets();
    expect(count($widgets))->toBe(2);
    foreach ($widgets as $widget) {
        expect($widget->getProvider())->toBe('intercom');
        expect($widget->getAccount())->toBe('eu');
        expect($widget->getCredentialEnv())->toBe('INTERCOM_TOKEN');
        expect($widget->getReadOnly())->toBeTrue();
    }
    expect($widgets[0]->getResource())->toBe('external-contact');
    expect($widgets[0]->getBindingField())->toBe('id');
    expect($widgets[0]->getTitle())->toBe('Intercom');
    expect($widgets[1]->getResource())->toBe('contact');
    expect($widgets[1]->getBindingRelation())->toBe('account');
    $widgets[0]->setAccount('mutated');
    expect($host->manifest()->getWidgets()[0]->getAccount())->toBe('eu');
});

it('replaces the same collection and name', function () {
    $integration = (new IntercomPlugin('us', 'INTERCOM_TOKEN', true))
        ->contact('customers', 'support', externalId: new RecordBinding('id'))
        ->contact('customers', 'support', contactId: new RecordBinding('cid'));
    expect(count($integration->widgets()))->toBe(1);
    expect($integration->widgets()[0]->resource)->toBe('contact');
});

it('accepts every supported region', function (string $region) {
    expect((new IntercomPlugin($region, 'INTERCOM_TOKEN', true))->widgets())->toBe([]);
})->with(['us', 'eu', 'au']);

it('rejects invalid regions', function (string $region) {
    expect(fn () => new IntercomPlugin($region, 'INTERCOM_TOKEN', true))->toThrow(InvalidArgumentException::class);
})->with(['', 'US', 'uk', 'https://api.intercom.io', 'api.eu.intercom.io', "eu\n", ' eu']);

it('rejects credentials and write-enabled declarations', function () {
    expect(fn () => new IntercomPlugin('us', 'actual-token', true))->toThrow(InvalidArgumentException::class);
    expect(fn () => new IntercomPlugin('us', 'INTERCOM_TOKEN', false))->toThrow(InvalidArgumentException::class);
});

it('requires exactly one binding', function () {
    $integration = new IntercomPlugin('us', 'INTERCOM_TOKEN', true);
    expect(fn () => $integration->contact('customers', 'none'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $integration->contact('customers', 'both', new RecordBinding('a'), new RecordBinding('b')))->toThrow(InvalidArgumentException::class);
    expect($integration->widgets())->toBe([]);
});

it('preserves valid widgets after invalid replacements', function () {
    $integration = (new IntercomPlugin('us', 'INTERCOM_TOKEN', true))->contact('customers', 'support', externalId: new RecordBinding('id'));
    expect(fn () => $integration->contact('customers', 'support'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $integration->contact('customers', 'support', contactId: new RecordBinding('__proto__')))->toThrow(InvalidArgumentException::class);
    expect($integration->widgets()[0]->resource)->toBe('external-contact');
});
