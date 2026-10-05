<?php

use Aviato\Laravel\Context\ActionContext;
use Aviato\Laravel\Context\ActionResult;
use Aviato\Laravel\Facades\Aviato;
use Aviato\Laravel\Tests\Fixtures\FixturePlugin;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(fn () => FixturePlugin::register());

it('calls actions without an agent', function () {
    $fake = Aviato::fake();

    $fake->executeAction('customers', 'Refund last invoice', [42], ['reason' => 'Duplicate'])
        ->assertSuccess('Refunded 42')
        ->assertInvalidated(['invoices']);
    $fake->executeAction('customers', 'Refund last invoice', [42])->assertError('A reason is required');
    $fake->executeAction('customers', 'Export')->assertFile('customers.csv', "id,name\n1,Acme\n", 'text/csv');
    $fake->executeAction('customers', 'Open in Stripe', ['cus_1'])->assertRedirect('https://dashboard.stripe.com/customers/cus_1');
    $fake->executeAction('customers', 'Summary', [7])->assertHtml('<p>Customer 7</p>');
});

it('fails assertions on the wrong result', function () {
    Aviato::fake()->executeAction('customers', 'Summary', [7])->assertSuccess();
})->throws(AssertionFailedError::class);

it('uses a fake caller that tests can change', function () {
    Aviato::action('customers', 'Whoami')->execute(fn (ActionContext $context) => $context->success("{$context->caller->name} ({$context->caller->role})"));

    $fake = Aviato::fake();
    $fake->executeAction('customers', 'Whoami')->assertSuccess('Test User (admin)');
    $fake->actingAs(['name' => 'Vera', 'role' => 'viewer'])->executeAction('customers', 'Whoami')->assertSuccess('Vera (viewer)');
});

it('serves records from an in-memory Data API and records writes', function () {
    Aviato::action('customers', 'Upgrade')->bulk()->execute(function (ActionContext $context) {
        foreach ($context->records() as $record) {
            $context->data()->update('customers', (string) $record['id'], ['plan' => 'pro']);
        }
        $context->data()->create('audit', ['event' => 'upgrade']);

        return $context->success('Upgraded '.count($context->recordIds));
    });
    Aviato::action('customers', 'Count team')->global()->execute(fn (ActionContext $context) => $context->success((string) count($context->records())));

    $fake = Aviato::fake()->withRecords('customers', [
        ['id' => 1, 'plan' => 'free'],
        ['id' => 2, 'plan' => 'team'],
        ['id' => 3, 'plan' => 'team'],
    ]);

    $fake->executeAction('customers', 'Count team', filter: ['plan' => 'team'])->assertSuccess('2');
    $fake->data()->assertNothingWritten();
    $fake->executeAction('customers', 'Upgrade', [1, 2])->assertSuccess('Upgraded 2');
    $fake->data()
        ->assertUpdated('customers', 1, ['plan' => 'pro'])
        ->assertUpdated('customers', 2)
        ->assertCreated('audit', ['event' => 'upgrade']);
    expect($fake->data()->get('customers', '3'))->toBe(['id' => 3, 'plan' => 'team']);
});

it('calls every other handler kind', function () {
    $fake = Aviato::fake();

    expect($fake->resolveForm('customers', 'Plan change', [7], ['plan' => 'team'], 'plan')->values)->toBe(['confirm' => true])
        ->and($fake->computeField('customers', 'fullName', [['first_name' => 'Ada', 'last_name' => 'Lovelace']]))->toBe(['Ada Lovelace'])
        ->and($fake->runHooks('customers', 'before', 'update', ['plan' => 'enterprise'])->rejectMessage)->toBe('Enterprise plans are set by sales')
        ->and($fake->runHooks('customers', 'before', 'update', ['plan' => 'pro'])->values)->toBe(['plan' => 'pro'])
        ->and($fake->resolveSegment('customers', 'vip'))->toBe(['filter' => ['plan' => 'team']])
        ->and($fake->search('customers', 'acme'))->toBe(['name' => ['$ilike' => '%acme%']])
        ->and($fake->writeField('customers', 'fullName', 'Grace Hopper'))->toBe(['first_name' => 'Grace', 'last_name' => 'Hopper'])
        ->and($fake->computeChart('customersByPlan')['mark'])->toBe('bar')
        ->and($fake->manifest()['actions'])->toHaveCount(6);
});

it('resolves invokable handler classes from the container', function () {
    Aviato::action('customers', 'Invokable')->execute(InvokableAction::class);

    Aviato::fake()->executeAction('customers', 'Invokable', [5])->assertSuccess('Invoked on 5 by '.InvokableAction::class);
});

final class InvokableAction
{
    public function __invoke(ActionContext $context): ActionResult
    {
        return $context->success("Invoked on {$context->recordId()} by ".self::class);
    }
}
