# Aviato for Laravel

Extend an Aviato agent with custom actions, computed fields, hooks, segments, search, charts and
custom datasources written in your Laravel application. The agent calls your app over the Aviato plugin protocol
(ConnectRPC JSON, requests signed with [Standard Webhooks](https://www.standardwebhooks.com/));
your code reads and writes records through the agent's Data API, under the caller's permissions
and audit trail.

Requires PHP 8.3+ and Laravel 12 or 13.

## Install

```bash
composer require getaviato/laravel
php artisan aviato:install --no-download   # publishes config/aviato.php
```

The SDK connects your application to an existing Aviato agent. Public agent binaries
are not yet available from the default download URL. Supply your own agent binary
or use an already running agent; omit `--no-download` only when a working agent
download URL has been configured.

## Configure

```dotenv
AVIATO_PLUGIN_SECRET=whsec_…              # shared with the agent
AVIATO_CONTROL_PLANE_URL=https://…        # from your Aviato project
AVIATO_AGENT_TOKEN=agt_…
# Optional
AVIATO_BASE_PATH=aviato                   # plugin endpoints live under /aviato
AVIATO_PLUGIN_URL=https://shop.example.com/aviato   # defaults to APP_URL + base path
AVIATO_DATABASE_URL=postgres://…          # defaults to your default DB connection
AVIATO_FILES_CACHE_STORE=redis            # where generated files wait for their download
```

The package registers `POST /aviato/aviato.plugin.v1.PluginService/{method}`,
`POST /aviato/aviato.plugin.v1.DatasourceService/{method}` and `GET /aviato/files/{ref}` outside the `web` middleware group (no session, no CSRF). Every
request must carry a valid signature less than five minutes old, or it is rejected with 401.

## Define an action

Register your plugin from a service provider's `boot` method:

```php
use Aviato\Laravel\Context\ActionContext;
use Aviato\Laravel\Context\FormContext;
use Aviato\Laravel\Context\HookContext;
use Aviato\Laravel\Facades\Aviato;

Aviato::action('customers', 'Refund last invoice')
    ->single()                                  // or ->bulk(), ->global()
    ->description('Refunds the latest paid invoice')
    ->form(
        ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']], 'required' => ['reason']],
        ['type' => 'VerticalLayout', 'elements' => [['type' => 'Control', 'scope' => '#/properties/reason']]],
    )
    ->execute(function (ActionContext $context) {
        $customer = $context->records()[0];   // read through the agent's Data API
        // $context->caller, $context->recordIds, $context->filter, $context->values …

        return $context->success("Refunded {$customer['email']}", invalidated: ['invoices']);
        // or ->error(), ->html(), ->file($name, $mime, $content), ->redirect(url: …), ->webhook(…)
    });

// Dynamic forms: called when the form opens and after each change.
Aviato::action('customers', 'Plan change')
    ->form(['type' => 'object', 'properties' => ['plan' => ['type' => 'string'], 'confirm' => ['type' => 'boolean']]])
    ->resolveForm(fn (FormContext $context) => $context->resolve(
        values: $context->changedField === 'plan' && $context->value('plan') === 'team' ? ['confirm' => true] : [],
    ))
    ->execute(fn (ActionContext $context) => $context->success('Plan changed'));

Aviato::computedField('customers', 'fullName')
    ->dependsOn('first_name', 'last_name')
    ->compute(fn (array $records) => array_map(fn ($r) => "{$r['first_name']} {$r['last_name']}", $records));

Aviato::hook('customers', 'before', 'update')->run(fn (HookContext $context) =>
    ($context->values['plan'] ?? null) === 'enterprise'
        ? $context->reject('Enterprise plans are set by sales')
        : $context->proceed());

Aviato::segment('customers', 'vip')->resolve(fn () => ['filter' => ['plan' => 'team']]);
Aviato::search('customers')->resolve(fn (string $query) => ['name' => ['$ilike' => "%{$query}%"]]);
Aviato::writeOverride('customers', 'fullName')->write(fn ($value) => array_combine(['first_name', 'last_name'], explode(' ', $value, 2) + ['', '']));
Aviato::chart('customersByPlan', 'customers')->compute(fn (?array $filter) => ['mark' => 'bar', 'data' => ['values' => []]]);
```

Handlers can also be invokable classes (`->execute(RefundLastInvoice::class)`), resolved from the
container. Throw `Aviato\Laravel\Exceptions\RpcException` (for example
`RpcException::permissionDenied('…')`) to answer with a specific error code.

### Custom datasources

Data the agent cannot reach through a database driver (an internal API, a SaaS, a file) can be
served by your code as custom collections, under the same roles, row scopes, masking and audit
trail as database collections:

```php
use Aviato\Laravel\Context\DatasourceContext;
use Aviato\Laravel\Context\DatasourceQuery;
use Aviato\Laravel\Definitions\CustomCollectionDefinition;
use Aviato\Laravel\Exceptions\DatasourceException;

Aviato::datasource()
    ->collection('tickets', fn (CustomCollectionDefinition $collection) => $collection
        ->field('id', 'number', nullable: false, readOnly: true)
        ->field('subject', 'string', nullable: false)
        ->field('status', 'enum', enumValues: ['open', 'closed'])
        // What `list` does itself; the agent does the rest in memory.
        ->capabilities(filterOperators: ['eq', 'in'], sort: true, count: true))
    ->list(fn (DatasourceQuery $query, DatasourceContext $context) => ['records' => Helpdesk::search($query), 'total' => Helpdesk::count($query)])
    ->create(fn (array $values) => $values['subject'] ?? null
        ? Helpdesk::create($values)
        : throw new DatasourceException('A subject is required', 'invalid'));
```

`get` (defaults to a lookup through `list`), `create`, `update` and `delete` are optional. For slow
sources, `->replication()` with a `listChanges` handler lets the agent keep a copy, refreshed on
the collection's interval (`->replication(intervalSeconds: 300)`) and on demand with
`Aviato::refreshReplica('tickets', $dataUrl)`. See
[the custom datasources guide](https://docs.getaviato.com/integrations/custom-datasources).

### Relations

Relations declared on your Eloquent models (`belongsTo`, `hasOne`, `hasMany`) are sent to the
agent as relation hints, discovered with Laravel's model inspector. Models in `app/Models` are
used by default; list them in `aviato.relations.models` to choose. Check what is sent with:

```bash
php artisan aviato:relations
```

## Run the agent

```bash
php artisan aviato:serve
```

It runs the downloaded binary with `AVIATO_CONTROL_PLANE_URL`, `AVIATO_AGENT_TOKEN`,
`AVIATO_DATABASE_URL` (built from your default connection), `AVIATO_PLUGIN_URL` and
`AVIATO_PLUGIN_SECRET`. The binary is downloaded from `aviato.agent.download_url`
(`{version}`, `{os}`, `{arch}` and `{ext}` are replaced); set `aviato.agent.checksum_url` to
verify its SHA-256.

## Testing

`Aviato::fake()` calls your handlers without an agent, with a fake caller and an in-memory
Data API:

```php
use Aviato\Laravel\Facades\Aviato;

it('refunds the last invoice', function () {
    $aviato = Aviato::fake()->withRecords('customers', [['id' => 42, 'email' => 'ada@example.com']]);

    $aviato->executeAction('customers', 'Refund last invoice', [42], ['reason' => 'Duplicate'])
        ->assertSuccess('Refunded ada@example.com')
        ->assertInvalidated(['invoices']);

    $aviato->actingAs(['role' => 'viewer'])
        ->executeAction('customers', 'Refund last invoice', [42])
        ->assertError();

    $aviato->data()->assertNothingWritten();
});
```

It also offers `resolveForm`, `computeField`, `runHooks`, `resolveSegment`, `search`,
`writeField`, `computeChart` and `manifest`, and for custom datasources `listCustomRecords`,
`getCustomRecord`, `createCustomRecord`, `updateCustomRecord`, `deleteCustomRecord`,
`listChanges` and `customCollections`.

## Custom summaries and forms

`Aviato::summary($collection, $document)` registers a native record overview.
Use `Aviato\Laravel\UI::document`, `UI::node`, and `UI::bind` to build the shared
JSON format, or load a document also used by the TypeScript and Go SDKs. For an
action, pass `UI::form($document)` as the second argument to
`->form($jsonSchema, $uiSchema)`.

The [custom UI guide](https://docs.getaviato.com/guides/custom-ui) covers metric
strips, property rows, reusable components, local state, field presets, and action
buttons. Rendering uses Aviato's native design system without customer scripts or
iframes. The agent still enforces permissions, validation, approvals, and auditing.

## Development

```bash
pnpm exec nx run @aviato/sdk-laravel:test       # Pest + Orchestra Testbench
pnpm exec nx run @aviato/sdk-laravel:lint       # Pint + Larastan (level 8)
pnpm exec nx run @aviato/sdk-laravel:generate   # regenerate generated/ from the protocol
pnpm exec nx run @aviato/fixture-laravel:conformance
```

The protocol messages in `generated/` come from `packages/protocol/proto` through
`buf generate` (remote plugin `buf.build/protocolbuffers/php`) and are serialized with the
`google/protobuf` runtime's JSON mapping. They are committed, so Composer users never need buf.

## License

MIT. See [LICENSE](LICENSE).
