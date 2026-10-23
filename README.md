# Aviato for Laravel

Extend an Aviato connector with custom actions, computed fields, hooks, segments, search, charts and
custom datasources written in your Laravel application. The connector calls your app over the Aviato plugin protocol
(ConnectRPC JSON, requests signed with [Standard Webhooks](https://www.standardwebhooks.com/));
your code reads and writes records through the connector's Data API, under the caller's permissions
and audit trail.

Requires PHP 8.3+ and Laravel 12 or 13.

## Install

```bash
composer require getaviato/laravel
php artisan aviato:install   # publishes config/aviato.php and downloads the connector
```

The connector binary comes from the public
[getaviato/connector releases](https://github.com/getaviato/connector/releases), at
`aviato.connector.version`. Pass `--no-download` when the application connects to a connector
that runs elsewhere.

## Configure

```dotenv
AVIATO_PLUGIN_SECRET=whsec_…              # shared with the connector
AVIATO_CONTROL_PLANE_URL=https://…        # from your Aviato project
AVIATO_CONNECTOR_TOKEN=agt_…
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
        $customer = $context->records()[0];   // read through the connector's Data API
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

Data the connector cannot reach through a database driver (an internal API, a SaaS, a file) can be
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
        // What `list` does itself; the connector does the rest in memory.
        ->capabilities(filterOperators: ['eq', 'in'], sort: true, count: true))
    ->list(fn (DatasourceQuery $query, DatasourceContext $context) => ['records' => Helpdesk::search($query), 'total' => Helpdesk::count($query)])
    ->create(fn (array $values) => $values['subject'] ?? null
        ? Helpdesk::create($values)
        : throw new DatasourceException('A subject is required', 'invalid'));
```

`get` (defaults to a lookup through `list`), `create`, `update` and `delete` are optional. For slow
sources, `->replication()` with a `listChanges` handler lets the connector keep a copy, refreshed on
the collection's interval (`->replication(intervalSeconds: 300)`) and on demand with
`Aviato::refreshReplica('tickets', $dataUrl)`. See
[the custom datasources guide](https://docs.getaviato.com/integrations/custom-datasources).

### Relations

Relations declared on your Eloquent models (`belongsTo`, `hasOne`, `hasMany`) are sent to the
connector as relation hints, discovered with Laravel's model inspector. Models in `app/Models` are
used by default; list them in `aviato.relations.models` to choose. Check what is sent with:

```bash
php artisan aviato:relations
```

## Run the connector

```bash
php artisan aviato:serve
```

It runs the downloaded binary with `AVIATO_CONTROL_PLANE_URL`, `AVIATO_CONNECTOR_TOKEN`,
`AVIATO_DATABASE_URL` (built from your default connection), `AVIATO_PLUGIN_URL` and
`AVIATO_PLUGIN_SECRET`. The binary is downloaded from `aviato.connector.download_url`
(`{version}`, `{os}`, `{arch}` and `{ext}` are replaced); set `aviato.connector.checksum_url` to
verify its SHA-256.

During development, `php artisan aviato:serve --dev` reloads the plugin's manifest every second
and waits for your application to answer. Add `--types=app/Aviato` to regenerate the collection
classes when the database schema changes.

Each developer can run their own development environment of a project instead of sharing a
token: `php artisan aviato:serve --dev --project=<project id>` (or `AVIATO_PROJECT` in `.env`
without `AVIATO_CONNECTOR_TOKEN`). The first run prints a link to approve this computer in the
dashboard; later runs reuse the credential stored in `~/.config/aviato`. `--login` approves
again. Add `--tunnel` to share it with MCP clients through a temporary Cloudflare quick
tunnel; the connector downloads Cloudflare's `cloudflared` the first time when it is not
installed. Both need connector 0.5.0 or later (`php artisan aviato:install` downloads it).

### Collection classes

```bash
php artisan aviato:types            # app/Aviato, namespace App\Aviato
php artisan aviato:types --check    # fails in CI when the classes are out of date
```

It writes a class per collection of your database, with a `COLLECTION` constant and one constant
per field, so plugin code names them without strings a typo breaks:

```php
use App\Aviato\Orders;

Aviato::action(Orders::COLLECTION, 'Refund')->single()->execute(/* … */);
```

## Testing

`Aviato::fake()` calls your handlers without a connector, with a fake caller and an in-memory
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
`listChanges` and `customCollections`. `$aviato->data()` asserts writes with `assertCreated`,
`assertUpdated`, `assertDeleted` and `assertNothingWritten`, and reads records with `records()`
and `get()`. The TypeScript and Go SDKs offer the same test kit under the same names.

## Custom summaries and forms

`Aviato::summary($collection, $document)` registers a native record overview.
Use `Aviato\Laravel\UI::document`, `UI::node`, and `UI::bind` to build the shared
JSON format, or load a document also used by the TypeScript and Go SDKs. For an
action, pass `UI::form($document)` as the second argument to
`->form($jsonSchema, $uiSchema)`.

The [custom UI guide](https://docs.getaviato.com/guides/custom-ui) covers metric
strips, property rows, reusable components, local state, field presets, and action
buttons. Rendering uses Aviato's native design system without customer scripts or
iframes. The connector still enforces permissions, validation, approvals, and auditing.

## Collection layouts

`Aviato::layout($collection, $layout)` declares how a collection looks in the
back-office: label, icon, field order and settings, search fields, default sort and
virtual relations. It replaces the layout edited in the dashboard, which then shows it
read-only:

```php
Aviato::layout('customers', [
    'label' => 'Clients',
    'fieldOrder' => ['name', 'email', 'plan'],
    'fields' => ['plan' => ['widget' => 'badge']],
    'searchFields' => ['name', 'email'],
    'defaultSort' => '-created_at',
]);
```

See [Declare layouts in code](https://docs.getaviato.com/guides/custom-ui#declare-layouts-in-code).

## Development

You need PHP 8.3+ and Composer 2 (CI uses PHP 8.4). The monorepo's `mise.toml` leaves PHP out
because mise builds it from source, so install it with your system package manager.

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

## Ready-to-use provider widgets

Use the typed `StripePlugin` implementation of `IntegrationPlugin` to register read-only
Stripe customer, subscription and invoice widgets with a local ID
column or a `belongsTo` relation. Credentials live in the **customer connector environment**;
SDK declarations contain only the environment variable name. The browser calls the
customer connector directly, and provider data does not pass through Aviato infrastructure.

See the [provider widget guide](https://docs.getaviato.com/docs/integrations/widgets)
for registration examples and exact key permissions. Restricted key prefixes do not prove
read-only permissions: the operator must configure Read/None permissions in Stripe and
confirm `readOnly`; the adapter itself only sends GET requests.

## Zendesk widgets

```php
use Aviato\Laravel\Integrations\ZendeskPlugin;
use Aviato\Laravel\Integrations\RecordBinding;
Aviato::use((new ZendeskPlugin('northstar', 'ZENDESK_WIDGET_TOKEN', true))
    ->tickets('customers', 'support', new RecordBinding('zendesk_user_id')));
```

The typed integration also supports user profiles and individual tickets. Bind to a
local ID column or one belongsTo relation. Install a customer connector with Zendesk
support before using these APIs. These examples require core SDK 0.3.0 (and NestJS adapter 0.2.0 when used).
SDK releases do not publish connector binaries.

Store the OAuth token on the **customer connector**, with `users:read` / `tickets:read`
scopes. Before each read, the connector verifies actual scopes and rejects write-enabled
tokens. Only the subdomain label and credential environment-variable name belong
in SDK configuration. No provider credentials or responses pass through Aviato's
control plane. See the [Zendesk guide](https://docs.getaviato.com/integrations/zendesk)
for all methods, permissions and release requirements.

## Intercom widgets

```php
use Aviato\Laravel\Integrations\IntercomPlugin;
use Aviato\Laravel\Integrations\RecordBinding;
Aviato::use((new IntercomPlugin('eu', 'INTERCOM_WIDGET_TOKEN', true))
    ->contact('customers', 'support', externalId: new RecordBinding('id')));
```

The contact card shows the profile, open conversations and open tickets of one contact.
Bind to a local ID column or one belongsTo relation with exactly one of `externalId`
(matched on the Intercom `external_id`) or `contactId` (the Intercom contact ID);
anything else throws. The region is exactly `us`, `eu` or `au`, never a URL. Install a
customer connector with Intercom support before using this API. This example requires SDK
release 0.7.0. SDK releases do not publish connector binaries.

Store the access token on the **customer connector** and declare it read-only. Only the
region and the credential environment-variable name belong in SDK configuration. No
provider credentials or responses pass through Aviato's control plane. Aviato cannot verify that the token is read-only; that depends on the Intercom app's permissions. See the [Intercom guide](https://docs.getaviato.com/integrations/intercom).
