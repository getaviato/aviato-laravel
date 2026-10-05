<?php

use Aviato\Laravel\Context\ActionContext;
use Aviato\Laravel\Exceptions\RpcException;
use Aviato\Laravel\Facades\Aviato;
use Aviato\Laravel\Tests\Fixtures\FixturePlugin;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => FixturePlugin::register());

dataset('caller', [[[
    'userId' => 'u1', 'name' => 'Ada', 'actorType' => 'human', 'role' => 'admin', 'organizationId' => 'o1',
    'projectId' => 'p1', 'environment' => 'production', 'requestId' => 'r1', 'invocationToken' => 'tok_123',
    'dataUrl' => 'http://agent.test/data',
]]]);

it('serves the manifest', function () {
    $this->rpc('GetManifest', ['agentProtocolVersion' => 1])
        ->assertOk()
        ->assertJsonPath('manifest.protocolVersion', 1)
        ->assertJsonPath('manifest.sdkName', 'getaviato/laravel')
        ->assertJsonPath('manifest.actions.0', [
            'collection' => 'customers',
            'name' => 'Refund last invoice',
            'scope' => 'ACTION_SCOPE_SINGLE',
            'formSchema' => ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']], 'required' => ['reason']],
        ])
        ->assertJsonPath('manifest.actions.1.scope', 'ACTION_SCOPE_BULK')
        ->assertJsonPath('manifest.actions.2.scope', 'ACTION_SCOPE_GLOBAL')
        ->assertJsonPath('manifest.actions.2.generatesFile', true)
        ->assertJsonPath('manifest.actions.5.dynamicForm', true)
        ->assertJsonPath('manifest.computedFields.0', ['collection' => 'customers', 'name' => 'fullName', 'type' => 'string', 'dependencies' => ['first_name', 'last_name']])
        ->assertJsonPath('manifest.hooks.0', ['collection' => 'customers', 'timing' => 'HOOK_TIMING_BEFORE', 'operation' => 'HOOK_OPERATION_UPDATE'])
        ->assertJsonPath('manifest.segments.0', ['collection' => 'customers', 'name' => 'vip'])
        ->assertJsonPath('manifest.searches.0', ['collection' => 'customers'])
        ->assertJsonPath('manifest.writeOverrides.0', ['collection' => 'customers', 'field' => 'fullName'])
        ->assertJsonPath('manifest.charts.0', ['name' => 'customersByPlan', 'collection' => 'customers']);
});

it('executes actions and returns each kind of result', function (array $caller) {
    $execute = fn (string $action, array $target, array $values = []) => $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => $action, 'target' => ['collection' => 'customers', ...$target], 'values' => $values]);

    $execute('Refund last invoice', ['recordIds' => ['42']], ['reason' => 'Duplicate'])
        ->assertOk()->assertExactJson(['result' => ['success' => ['message' => 'Refunded 42', 'invalidated' => ['invoices']]]]);
    $execute('Refund last invoice', ['recordIds' => ['42']])
        ->assertOk()->assertExactJson(['result' => ['error' => ['message' => 'A reason is required']]]);
    $execute('Tag', ['recordIds' => ['1', '2', '3']], ['tag' => 'vip'])
        ->assertJsonPath('result.success.message', 'Tagged 3 customers');
    $execute('Open in Stripe', ['recordIds' => ['cus_1']])
        ->assertExactJson(['result' => ['redirect' => ['url' => 'https://dashboard.stripe.com/customers/cus_1']]]);
    $execute('Summary', ['recordIds' => ['7']])
        ->assertExactJson(['result' => ['html' => ['html' => '<p>Customer 7</p>']]]);
    $execute('Export', ['filter' => ['plan' => 'team']])
        ->assertJsonPath('result.file.name', 'customers.csv')
        ->assertJsonPath('result.file.mimeType', 'text/csv')
        ->assertJson(fn ($json) => $json->whereType('result.file.ref', 'string')->etc());
})->with('caller');

it('returns webhook results', function (array $caller) {
    Aviato::action('customers', 'Ping')->execute(fn (ActionContext $context) => $context->webhook('https://hooks.test', headers: ['x-id' => '1'], body: '{}'));

    $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => 'Ping', 'target' => ['collection' => 'customers']])
        ->assertExactJson(['result' => ['webhook' => ['url' => 'https://hooks.test', 'method' => 'POST', 'headers' => ['x-id' => '1'], 'body' => '{}']]]);
})->with('caller');

it('passes the target of global actions', function (array $caller) {
    Aviato::action('customers', 'Inspect')->global()->execute(fn (ActionContext $context) => $context->success(json_encode([$context->filter, $context->segment, $context->search, $context->caller->userId])));

    $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => 'Inspect', 'target' => ['collection' => 'customers', 'filter' => ['plan' => ['$in' => ['pro', 'team']], 'tags' => []], 'segment' => 'vip', 'search' => 'acme']])
        ->assertJsonPath('result.success.message', '[{"plan":{"$in":["pro","team"]},"tags":[]},"vip","acme","u1"]');
})->with('caller');

it('treats a null action result as success', function (array $caller) {
    Aviato::action('customers', 'Noop')->execute(fn () => null);

    $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => 'Noop', 'target' => ['collection' => 'customers']])
        ->assertExactJson(['result' => ['success' => []]]);
})->with('caller');

it('reports unknown actions as not_found', function (array $caller) {
    $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => 'Does not exist', 'target' => ['collection' => 'customers', 'recordIds' => ['1']]])
        ->assertNotFound()
        ->assertExactJson(['code' => 'not_found', 'message' => 'Unknown action customers.Does not exist']);
})->with('caller');

it('requires a caller', function () {
    $this->rpc('ExecuteAction', ['action' => 'Summary', 'target' => ['collection' => 'customers']])
        ->assertStatus(400)->assertJsonPath('code', 'invalid_argument');
});

it('rejects malformed requests', function () {
    $body = '{"action": 12, "target": "nope"';
    $this->call('POST', '/aviato/aviato.plugin.v1.PluginService/ExecuteAction', [], [], [], $this->serverHeaders($this->signedHeaders($body)), $body)
        ->assertStatus(400)->assertJsonPath('code', 'invalid_argument');
});

it('ignores unknown fields from newer agents', function (array $caller) {
    $this->rpc('ExecuteAction', ['caller' => [...$caller, 'futureField' => 1], 'action' => 'Summary', 'target' => ['collection' => 'customers', 'recordIds' => ['7']], 'somethingNew' => true])
        ->assertOk();
})->with('caller');

it('answers unknown methods with unimplemented', function () {
    $this->rpc('DoesNotExist')->assertStatus(501)->assertJsonPath('code', 'unimplemented');
});

it('only speaks the JSON encoding', function () {
    $headers = [...$this->signedHeaders('{}'), 'content-type' => 'application/proto'];
    $this->call('POST', '/aviato/aviato.plugin.v1.PluginService/GetManifest', [], [], [], $this->serverHeaders($headers), '{}')->assertStatus(415);
});

it('maps exceptions thrown by handlers to Connect errors', function (array $caller) {
    Aviato::action('customers', 'Denied')->execute(fn () => throw RpcException::permissionDenied('Not for you'));
    Aviato::action('customers', 'Broken')->execute(fn () => throw new RuntimeException('secret detail'));
    config()->set('app.debug', false);

    $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => 'Denied', 'target' => ['collection' => 'customers']])
        ->assertForbidden()->assertExactJson(['code' => 'permission_denied', 'message' => 'Not for you']);
    $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => 'Broken', 'target' => ['collection' => 'customers']])
        ->assertStatus(500)->assertExactJson(['code' => 'internal', 'message' => 'Internal error in the Aviato plugin']);
})->with('caller');

it('resolves dynamic forms', function (array $caller) {
    $resolve = fn (array $values, string $changed) => $this->rpc('ResolveForm', ['caller' => $caller, 'action' => 'Plan change', 'target' => ['collection' => 'customers', 'recordIds' => ['7']], 'values' => $values, 'changedField' => $changed]);

    $resolve(['plan' => 'team'], 'plan')->assertOk()->assertJsonPath('values', ['confirm' => true])->assertJsonPath('formSchema.properties.plan.enum', ['free', 'pro', 'team']);
    $resolve(['plan' => 'pro'], 'plan')->assertOk()->assertJsonPath('values', []);
})->with('caller');

it('returns the static form of actions without a resolver', function (array $caller) {
    $this->rpc('ResolveForm', ['caller' => $caller, 'action' => 'Tag', 'target' => ['collection' => 'customers']])
        ->assertExactJson(['formSchema' => ['type' => 'object', 'properties' => ['tag' => ['type' => 'string']]]]);
})->with('caller');

it('computes fields for a page of records', function (array $caller) {
    $this->rpc('ComputeFields', ['caller' => $caller, 'collection' => 'customers', 'fields' => ['fullName'], 'records' => [['first_name' => 'Ada', 'last_name' => 'Lovelace'], ['first_name' => 'Alan', 'last_name' => 'Turing']]])
        ->assertExactJson(['values' => [['fullName' => 'Ada Lovelace'], ['fullName' => 'Alan Turing']]]);
    $this->rpc('ComputeFields', ['caller' => $caller, 'collection' => 'customers', 'fields' => ['nope'], 'records' => [[]]])
        ->assertNotFound();
})->with('caller');

it('writes virtual fields', function (array $caller) {
    $this->rpc('WriteField', ['caller' => $caller, 'collection' => 'customers', 'field' => 'fullName', 'value' => 'Grace Brewster Hopper', 'record' => []])
        ->assertExactJson(['patch' => ['first_name' => 'Grace', 'last_name' => 'Brewster Hopper']]);
    $this->rpc('WriteField', ['caller' => $caller, 'collection' => 'customers', 'field' => 'email', 'value' => 'x'])
        ->assertNotFound();
})->with('caller');

it('runs hooks', function (array $caller) {
    $hook = fn (array $values) => $this->rpc('RunHook', ['caller' => $caller, 'collection' => 'customers', 'timing' => 'HOOK_TIMING_BEFORE', 'operation' => 'HOOK_OPERATION_UPDATE', 'recordIds' => ['1'], 'values' => $values]);

    $hook(['plan' => 'enterprise'])->assertExactJson(['rejectMessage' => 'Enterprise plans are set by sales']);
    $hook(['plan' => 'pro'])->assertExactJson(['values' => ['plan' => 'pro']]);
    // No hook for after-create: the operation goes through untouched.
    $this->rpc('RunHook', ['caller' => $caller, 'collection' => 'customers', 'timing' => 'HOOK_TIMING_AFTER', 'operation' => 'HOOK_OPERATION_CREATE'])
        ->assertExactJson([]);
})->with('caller');

it('chains hooks, each seeing the values of the previous one', function (array $caller) {
    Aviato::hook('orders', 'before', 'create')->run(fn ($context) => $context->proceed([...$context->values, 'status' => 'new']));
    Aviato::hook('orders', 'before', 'create')->run(fn ($context) => ['values' => [...$context->values, 'seen' => $context->values['status']]]);

    $this->rpc('RunHook', ['caller' => $caller, 'collection' => 'orders', 'timing' => 'HOOK_TIMING_BEFORE', 'operation' => 'HOOK_OPERATION_CREATE', 'values' => ['total' => 10]])
        ->assertExactJson(['values' => ['total' => 10, 'status' => 'new', 'seen' => 'new']]);
})->with('caller');

it('resolves segments to a filter or to ids', function (array $caller) {
    Aviato::segment('customers', 'mine')->resolve(fn () => ['ids' => [1, '2']]);

    $this->rpc('ResolveSegment', ['caller' => $caller, 'collection' => 'customers', 'segment' => 'vip'])->assertExactJson(['filter' => ['plan' => 'team']]);
    $this->rpc('ResolveSegment', ['caller' => $caller, 'collection' => 'customers', 'segment' => 'mine'])->assertExactJson(['recordIds' => ['ids' => ['1', '2']]]);
    $this->rpc('ResolveSegment', ['caller' => $caller, 'collection' => 'customers', 'segment' => 'nope'])->assertNotFound();
})->with('caller');

it('builds search filters', function (array $caller) {
    $this->rpc('Search', ['caller' => $caller, 'collection' => 'customers', 'query' => 'acme'])->assertExactJson(['filter' => ['name' => ['$ilike' => '%acme%']]]);
    $this->rpc('Search', ['caller' => $caller, 'collection' => 'invoices', 'query' => 'acme'])->assertNotFound();
})->with('caller');

it('computes charts', function (array $caller) {
    $this->rpc('ComputeChart', ['caller' => $caller, 'chart' => 'customersByPlan'])->assertJsonPath('vegaLite.mark', 'bar')->assertJsonPath('vegaLite.data.values', []);
    $this->rpc('ComputeChart', ['caller' => $caller, 'chart' => 'nope'])->assertNotFound();
})->with('caller');

it('reads records through the Data API with the invocation token', function (array $caller) {
    Http::fake([
        'agent.test/data/aviato.plugin.v1.DataService/Get' => fn (Request $request) => Http::response(['record' => ['id' => $request['id'], 'email' => "c{$request['id']}@example.com"]]),
        'agent.test/data/aviato.plugin.v1.DataService/List' => Http::response(['records' => [['id' => 9, 'email' => 'c9@example.com']], 'total' => '1']),
    ]);
    Aviato::action('customers', 'Emails')->bulk()->execute(fn (ActionContext $context) => $context->success(implode(',', array_column($context->records(), 'email'))));

    $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => 'Emails', 'target' => ['collection' => 'customers', 'recordIds' => ['1', '2']]])
        ->assertJsonPath('result.success.message', 'c1@example.com,c2@example.com');
    $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => 'Emails', 'target' => ['collection' => 'customers', 'filter' => ['plan' => 'team']]])
        ->assertJsonPath('result.success.message', 'c9@example.com');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer tok_123')
        && $request->url() === 'http://agent.test/data/aviato.plugin.v1.DataService/List'
        && $request['collection'] === 'customers' && $request['filter'] === ['plan' => 'team'] && $request['limit'] === 200);
})->with('caller');

it('writes records through the Data API and surfaces its errors', function (array $caller) {
    Http::fake([
        '*DataService/Update' => Http::response(['record' => ['id' => '1', 'plan' => 'pro']]),
        '*DataService/Delete' => Http::response(['code' => 'permission_denied', 'message' => 'Viewers cannot delete'], 403),
    ]);
    Aviato::action('customers', 'Upgrade')->execute(function (ActionContext $context) {
        $record = $context->data()->update('customers', $context->recordId(), ['plan' => 'pro']);
        $context->data()->delete('customers', $context->recordId());

        return $context->success($record['plan']);
    });

    $this->rpc('ExecuteAction', ['caller' => $caller, 'action' => 'Upgrade', 'target' => ['collection' => 'customers', 'recordIds' => ['1']]])
        ->assertForbidden()->assertExactJson(['code' => 'permission_denied', 'message' => 'Viewers cannot delete']);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Update') && $request['values'] === ['plan' => 'pro'] && $request['id'] === '1');
})->with('caller');
