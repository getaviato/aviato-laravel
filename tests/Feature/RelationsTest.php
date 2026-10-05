<?php

use Aviato\Laravel\Facades\Aviato;
use Aviato\Laravel\Tests\Fixtures\Models\Customer;
use Aviato\Laravel\Tests\Fixtures\Models\Invoice;
use Aviato\Laravel\Tests\Fixtures\Models\Profile;

beforeEach(fn () => config()->set('aviato.relations.models', [Customer::class, Invoice::class, Profile::class]));

it('turns Eloquent relations into relation hints', function () {
    expect(array_map(fn ($hint) => $hint->toArray(), Aviato::relationHints()))->toBe([
        ['collection' => 'customers', 'name' => 'invoices', 'kind' => 'hasMany', 'foreignKey' => ['customer_id'], 'target' => 'invoices', 'targetKey' => ['id']],
        ['collection' => 'customers', 'name' => 'profile', 'kind' => 'hasOne', 'foreignKey' => ['owner_id'], 'target' => 'customer_profiles', 'targetKey' => ['uuid']],
        ['collection' => 'invoices', 'name' => 'customer', 'kind' => 'belongsTo', 'foreignKey' => ['customer_id'], 'target' => 'customers', 'targetKey' => ['id']],
    ]);
});

it('sends relation hints in the manifest', function () {
    Aviato::relation('orders', 'buyer', 'belongsTo', 'buyer_ref', 'customers', 'external_id');

    $this->rpc('GetManifest')
        ->assertJsonPath('manifest.relationHints.0', ['collection' => 'orders', 'name' => 'buyer', 'kind' => 'belongsTo', 'foreignKey' => ['buyer_ref'], 'target' => 'customers', 'targetKey' => ['external_id']])
        ->assertJsonCount(4, 'manifest.relationHints');
});

it('can be disabled', function () {
    config()->set('aviato.relations.enabled', false);

    expect(Aviato::relationHints())->toBe([]);
});

it('lists relation hints on the command line', function () {
    $this->artisan('aviato:relations')
        ->expectsTable(['Collection', 'Relation', 'Kind', 'Foreign key', 'Target', 'Target key'], [
            ['customers', 'invoices', 'hasMany', 'customer_id', 'invoices', 'id'],
            ['customers', 'profile', 'hasOne', 'owner_id', 'customer_profiles', 'uuid'],
            ['invoices', 'customer', 'belongsTo', 'customer_id', 'customers', 'id'],
        ])
        ->assertSuccessful();
    $this->artisan('aviato:relations', ['--json' => true])->expectsOutputToContain('"foreignKey": [')->assertSuccessful();
});
