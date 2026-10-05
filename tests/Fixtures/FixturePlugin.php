<?php

namespace Aviato\Laravel\Tests\Fixtures;

use Aviato\Laravel\Context\ActionContext;
use Aviato\Laravel\Context\FormContext;
use Aviato\Laravel\Context\HookContext;
use Aviato\Laravel\Facades\Aviato;

/** The conformance fixture plugin (packages/conformance/FIXTURE.md). */
final class FixturePlugin
{
    public static function register(): void
    {
        Aviato::action('customers', 'Refund last invoice')
            ->single()
            ->form(['type' => 'object', 'properties' => ['reason' => ['type' => 'string']], 'required' => ['reason']])
            ->execute(fn (ActionContext $context) => $context->value('reason')
                ? $context->success("Refunded {$context->recordId()}", invalidated: ['invoices'])
                : $context->error('A reason is required'));

        Aviato::action('customers', 'Tag')
            ->bulk()
            ->form(['type' => 'object', 'properties' => ['tag' => ['type' => 'string']]])
            ->execute(fn (ActionContext $context) => $context->success('Tagged '.count($context->recordIds).' customers'));

        Aviato::action('customers', 'Export')
            ->global()
            ->generatesFile()
            ->execute(fn (ActionContext $context) => $context->file('customers.csv', 'text/csv', "id,name\n1,Acme\n"));

        Aviato::action('customers', 'Open in Stripe')
            ->execute(fn (ActionContext $context) => $context->redirect(url: "https://dashboard.stripe.com/customers/{$context->recordId()}"));

        Aviato::action('customers', 'Summary')
            ->execute(fn (ActionContext $context) => $context->html("<p>Customer {$context->recordId()}</p>"));

        Aviato::action('customers', 'Plan change')
            ->form(['type' => 'object', 'properties' => ['plan' => ['type' => 'string', 'enum' => ['free', 'pro', 'team']], 'confirm' => ['type' => 'boolean']]])
            ->resolveForm(fn (FormContext $context) => $context->resolve(
                values: $context->changedField === 'plan' && $context->value('plan') === 'team' ? ['confirm' => true] : [],
            ))
            ->execute(fn (ActionContext $context) => $context->success('Plan changed'));

        Aviato::computedField('customers', 'fullName')
            ->type('string')
            ->dependsOn('first_name', 'last_name')
            ->compute(fn (array $records) => array_map(fn (array $record) => "{$record['first_name']} {$record['last_name']}", $records));

        Aviato::hook('customers', 'before', 'update')
            ->run(fn (HookContext $context) => ($context->values['plan'] ?? null) === 'enterprise'
                ? $context->reject('Enterprise plans are set by sales')
                : $context->proceed($context->values));

        Aviato::segment('customers', 'vip')->resolve(fn () => ['filter' => ['plan' => 'team']]);

        Aviato::search('customers')->resolve(fn (string $query) => ['name' => ['$ilike' => "%{$query}%"]]);

        Aviato::writeOverride('customers', 'fullName')->write(function (mixed $value): array {
            [$first, $last] = explode(' ', is_scalar($value) ? (string) $value : '', 2) + ['', ''];

            return ['first_name' => $first, 'last_name' => $last];
        });

        Aviato::chart('customersByPlan', 'customers')->compute(fn () => [
            'mark' => 'bar',
            'encoding' => ['x' => ['field' => 'plan'], 'y' => ['aggregate' => 'count']],
            'data' => ['values' => []],
        ]);
    }
}
