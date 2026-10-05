<?php

use Aviato\Laravel\Integrations\IntegrationPlugin;
use Aviato\Laravel\Integrations\IntegrationWidget;
use Aviato\Laravel\Integrations\RecordBinding;
use Aviato\Laravel\Integrations\StripePlugin;
use Aviato\Laravel\Plugin;

it('registers typed Stripe resources and snapshots their declarations', function () {
    $binding = new RecordBinding('stripe_id', 'account');
    $stripe = (new StripePlugin('STRIPE_WIDGET_KEY', true))->customer('customers', 'billing', $binding);
    expect($stripe)->toBeInstanceOf(IntegrationPlugin::class);
    $plugin = app(Plugin::class)->use($stripe);
    $stripe->invoices('customers', 'billing', $binding);
    expect($plugin->manifest()->getWidgets()[0]->getResource())->toBe('customer');
    $plugin->use($stripe);
    $widgets = $plugin->manifest()->getWidgets();
    expect(count($widgets))->toBe(1);
    expect($widgets[0]->getResource())->toBe('invoices');
    expect($widgets[0]->getBindingRelation())->toBe('account');
    expect($widgets[0]->getCredentialEnv())->toBe('STRIPE_WIDGET_KEY');
    $stripe->subscriptions('customers', 'subscriptions', $binding)
        ->subscription('subscriptions', 'one', new RecordBinding('subscription_id'))
        ->invoice('invoices', 'one', new RecordBinding('invoice_id'));
    $plugin->use($stripe);
    expect($plugin->manifest()->getWidgets()[2]->getBindingField())->toBe('subscription_id');
    expect($plugin->manifest()->getWidgets()[3]->getBindingField())->toBe('invoice_id');
});

it('refuses keys in metadata and requires read-only confirmation', function () {
    expect(fn () => new StripePlugin('rk_test_private', true))->toThrow(InvalidArgumentException::class);
    expect(fn () => new StripePlugin('STRIPE_WIDGET_KEY', false))->toThrow(InvalidArgumentException::class);
});

it('accepts other implementations and rejects invalid declarations atomically', function () {
    $integration = new class implements IntegrationPlugin
    {
        public bool $invalid = true;

        public function widgets(): array
        {
            $widgets = [new IntegrationWidget('customers', 'tickets', 'custom', 'tickets', new RecordBinding('external_id'), 'CUSTOM_KEY', true)];
            if ($this->invalid) {
                $widgets[] = new IntegrationWidget('customers', 'invalid', 'custom', 'tickets', new RecordBinding('external_id'), 'CUSTOM_KEY', false);
            }

            return $widgets;
        }
    };
    $plugin = app(Plugin::class);
    expect(fn () => $plugin->use($integration))->toThrow(InvalidArgumentException::class);
    expect(count($plugin->manifest()->getWidgets()))->toBe(0);
    $integration->invalid = false;
    $plugin->use($integration);
    expect($plugin->manifest()->getWidgets()[0]->getProvider())->toBe('custom');
});

it('rejects malformed credential references', function (string $credential) {
    expect(fn () => new StripePlugin($credential, true))->toThrow(InvalidArgumentException::class);
})->with(['', 'rk_test_secret', 'STRIPE-KEY', '1STRIPE_KEY', ' STRIPE_KEY', "STRIPE_KEY\n", "STRIPE_KEY\r\n", str_repeat('A', 129)]);

it('preserves a valid builder declaration when its replacement is invalid', function (string $field) {
    $stripe = (new StripePlugin('STRIPE_KEY', true))->customer('customers', 'billing', new RecordBinding('stripe_id'));
    $before = $stripe->widgets();
    expect(fn () => $stripe->invoices('customers', 'billing', new RecordBinding($field)))->toThrow(InvalidArgumentException::class);
    expect($stripe->widgets())->toBe($before);
})->with(['', '__proto__', 'constructor', 'prototype', str_repeat('f', 129)]);

it('keeps collection and name pairs distinct, including dotted names', function () {
    $stripe = new StripePlugin('STRIPE_KEY', true);
    $pairs = [['orders.account', 'billing'], ['orders', 'account.billing'], ['customers', 'billing'], ['customers', 'invoices']];
    foreach ($pairs as [$collection, $name]) {
        $stripe->customer($collection, $name, new RecordBinding('stripe_id'));
    }
    $host = app(Plugin::class)->use($stripe);
    $widgets = $host->manifest()->getWidgets();
    expect(count($widgets))->toBe(4);
    foreach ($pairs as $index => [$collection, $name]) {
        expect($widgets[$index]->getCollection())->toBe($collection);
        expect($widgets[$index]->getName())->toBe($name);
    }
});

it('does not partially replace host state when a provider throws', function () {
    $host = app(Plugin::class)->use((new StripePlugin('STRIPE_KEY', true))->customer('customers', 'billing', new RecordBinding('stripe_id')));
    $before = $host->manifest()->serializeToJsonString();
    $integration = new class implements IntegrationPlugin
    {
        public function widgets(): array
        {
            $replacement = new IntegrationWidget('customers', 'billing', 'stripe', 'invoices', new RecordBinding('other_id'), 'OTHER_ACCOUNT', true);
            $invalid = new IntegrationWidget('orders', 'new', 'stripe', 'invoices', new RecordBinding('stripe_id'), 'rk_live_secret', true);

            return [$replacement, $invalid];
        }
    };
    expect(fn () => $host->use($integration))->toThrow(InvalidArgumentException::class);
    expect($host->manifest()->serializeToJsonString())->toBe($before);
});

it('preserves unrelated registrations and accepts an empty integration as a no-op', function () {
    $host = app(Plugin::class)
        ->use((new StripePlugin('FIRST_KEY', true))->customer('customers', 'billing', new RecordBinding('stripe_id')))
        ->use((new StripePlugin('SECOND_KEY', true))->invoices('orders', 'billing', new RecordBinding('stripe_id', 'customer')));
    $host->use((new StripePlugin('FIRST_KEY', true))->subscription('customers', 'billing', new RecordBinding('subscription_id')));
    $before = $host->manifest()->serializeToJsonString();
    $host->use(new StripePlugin('UNUSED_KEY', true));
    expect($host->manifest()->serializeToJsonString())->toBe($before);
    $widgets = $host->manifest()->getWidgets();
    expect(count($widgets))->toBe(2);
    expect($widgets[0]->getResource())->toBe('subscription');
    expect($widgets[0]->getBindingField())->toBe('subscription_id');
    expect($widgets[1]->getCredentialEnv())->toBe('SECOND_KEY');
});

it('does not let a caller mutate registered widgets through a returned manifest', function () {
    $host = app(Plugin::class)->use((new StripePlugin('STRIPE_KEY', true))->customer('customers', 'billing', new RecordBinding('stripe_id')));
    $exposed = $host->manifest()->getWidgets()[0];
    $exposed->setCredentialEnv('OTHER_ACCOUNT');
    $exposed->setBindingField('other_id');
    expect($host->manifest()->getWidgets()[0]->getCredentialEnv())->toBe('STRIPE_KEY');
    expect($host->manifest()->getWidgets()[0]->getBindingField())->toBe('stripe_id');
});

it('exposes immutable bindings and declaration values', function () {
    $binding = new RecordBinding('stripe_id', 'account');
    $stripe = (new StripePlugin('STRIPE_KEY', true))->customer('customers', 'billing', $binding);
    $widget = $stripe->widgets()[0];
    expect(fn () => $binding->field = 'changed')->toThrow(Error::class);
    expect(fn () => $widget->credentialEnv = 'OTHER_ACCOUNT')->toThrow(Error::class);
    $copy = $stripe->widgets();
    array_pop($copy);
    expect(count($stripe->widgets()))->toBe(1);
});

it('validates metadata boundaries', function (string $property, string $value) {
    $values = ['collection' => 'customers', 'name' => 'billing', 'provider' => 'stripe', 'resource' => 'invoices', 'binding' => new RecordBinding('stripe_id'), 'credentialEnv' => 'STRIPE_KEY', 'readOnly' => true, 'title' => ''];
    $values[$property] = $value;
    expect(fn () => new IntegrationWidget(...$values))->toThrow(InvalidArgumentException::class);
})->with([
    'collection' => ['collection', ''],
    'name' => ['name', 'constructor'],
    'provider' => ['provider', 'https://stripe.com'],
    'resource' => ['resource', '../customers'],
    'title' => ['title', str_repeat('t', 161)],
]);

it('serializes each typed builder to the correct wire resource', function (string $method) {
    $stripe = new StripePlugin('STRIPE_KEY', true);
    $stripe->$method('records', 'billing', new RecordBinding('external_id', 'account'), 'Billing');
    $wire = app(Plugin::class)->use($stripe)->manifest()->getWidgets()[0];
    expect($wire->getProvider())->toBe('stripe');
    expect($wire->getResource())->toBe($method);
    expect($wire->getBindingField())->toBe('external_id');
    expect($wire->getBindingRelation())->toBe('account');
    expect($wire->getCollection())->toBe('records');
    expect($wire->getName())->toBe('billing');
    expect($wire->getTitle())->toBe('Billing');
    expect($wire->getReadOnly())->toBeTrue();
})->with(['customer', 'subscriptions', 'invoices', 'subscription', 'invoice']);
