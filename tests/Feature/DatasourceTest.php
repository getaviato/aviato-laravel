<?php

use Aviato\Laravel\Context\DatasourceContext;
use Aviato\Laravel\Context\DatasourceQuery;
use Aviato\Laravel\Definitions\CustomCollectionDefinition;
use Aviato\Laravel\Exceptions\DatasourceException;
use Aviato\Laravel\Exceptions\InvalidDefinitionException;
use Aviato\Laravel\Exceptions\RpcException;
use Aviato\Laravel\Facades\Aviato;
use Aviato\Laravel\Plugin;
use Aviato\Laravel\Testing\FakeDataClient;
use Aviato\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use StandardWebhooks\Webhook;

/** Calls a DatasourceService method over HTTP with a signed ConnectRPC JSON body. */
function datasourceRpc(string $method, array $request = [])
{
    $body = $request === [] ? '{}' : (string) json_encode($request);

    return test()->call('POST', "/aviato/aviato.plugin.v1.DatasourceService/{$method}", [], [], [], test()->serverHeaders(test()->signedHeaders($body)), $body);
}

beforeEach(function () {
    $this->seen = null;
    $tickets = [
        ['id' => 1, 'subject' => 'Login broken', 'status' => 'open'],
        ['id' => 2, 'subject' => 'Invoice copy', 'status' => 'closed'],
    ];

    Aviato::datasource()
        ->collection('tickets', fn (CustomCollectionDefinition $collection) => $collection
            ->field('id', 'number', nullable: false, readOnly: true)
            ->field('subject')
            ->field('status', 'enum', enumValues: ['open', 'closed'])
            ->relation('customer', 'belongsTo', 'customer_id', 'customers')
            ->capabilities(filterOperators: ['eq'], sort: true))
        ->list(function (DatasourceQuery $query, DatasourceContext $context) use (&$tickets) {
            $this->seen = [$context->collection, $context->caller?->userId, $query];
            $status = $query->filter['status']['$eq'] ?? null;
            $records = array_values(array_filter($tickets, fn (array $ticket) => $status === null || $ticket['status'] === $status));

            return ['records' => ($query->sort[0]['direction'] ?? 'asc') === 'desc' ? array_reverse($records) : $records];
        })
        ->create(function (array $values) use (&$tickets) {
            if (! isset($values['subject'])) {
                throw new DatasourceException('A subject is required', 'invalid');
            }

            return $tickets[] = ['id' => count($tickets) + 1, 'status' => 'open', ...$values];
        });

    Aviato::datasource()
        ->replication()
        ->collection('rates', fn (CustomCollectionDefinition $collection) => $collection
            ->primaryKey('currency')
            ->field('currency')
            ->field('rate', 'number')
            ->replication(intervalSeconds: 60))
        ->list(fn () => [])
        ->listChanges(fn (string $cursor, DatasourceContext $context) => $cursor === ''
            ? ['records' => [['currency' => 'EUR', 'rate' => 1, 'caller' => $context->caller]], 'nextCursor' => '1', 'hasMore' => true]
            : ['records' => [], 'deletedIds' => ['USD'], 'nextCursor' => '2']);
});

dataset('caller', [[[
    'userId' => 'u1', 'name' => 'Ada', 'actorType' => 'human', 'role' => 'admin', 'organizationId' => 'o1',
    'projectId' => 'p1', 'environment' => 'production', 'requestId' => 'r1', 'invocationToken' => 'tok_123',
    'dataUrl' => 'http://connector.test/data',
]]]);

it('announces custom datasources in the manifest and describes them', function () {
    $this->rpc('GetManifest', ['agentProtocolVersion' => 1])->assertJsonPath('manifest.datasource', true);

    datasourceRpc('DescribeCollections')
        ->assertOk()
        ->assertJsonPath('collections.0', [
            'name' => 'tickets',
            'fields' => [
                ['name' => 'id', 'type' => 'number', 'readOnly' => true],
                ['name' => 'subject', 'type' => 'string', 'nullable' => true],
                ['name' => 'status', 'type' => 'enum', 'enumValues' => ['open', 'closed'], 'nullable' => true],
            ],
            'primaryKey' => ['id'],
            'relations' => [['collection' => 'tickets', 'name' => 'customer', 'kind' => 'belongsTo', 'foreignKey' => ['customer_id'], 'target' => 'customers', 'targetKey' => ['id']]],
            'capabilities' => ['filterOperators' => ['eq'], 'sort' => true, 'create' => true],
            'strategy' => 'DATASOURCE_STRATEGY_TRANSLATION',
        ])
        ->assertJsonPath('collections.1.primaryKey', ['currency'])
        ->assertJsonPath('collections.1.strategy', 'DATASOURCE_STRATEGY_REPLICATION')
        ->assertJsonPath('collections.1.replicationIntervalSeconds', 60);
});

it('does not announce a datasource without collections', function () {
    $plugin = new Plugin(fn () => new FakeDataClient);
    $plugin->datasource();

    expect($plugin->manifest()->getDatasource())->toBeFalse();
});

it('lists with the filter, sort and caller the connector sends', function (array $caller) {
    datasourceRpc('List', ['caller' => $caller, 'collection' => 'tickets', 'filter' => ['status' => ['$eq' => 'open']], 'sort' => '-id', 'limit' => 10, 'includeTotal' => true])
        ->assertOk()
        ->assertExactJson(['records' => [['id' => 1, 'subject' => 'Login broken', 'status' => 'open']], 'total' => '1']);

    expect($this->seen)->toEqual(['tickets', 'u1', new DatasourceQuery(['status' => ['$eq' => 'open']], [['field' => 'id', 'direction' => 'desc']], 10, 0, [], true)]);
})->with('caller');

it('gets a record through list when no get handler is given', function (array $caller) {
    datasourceRpc('Get', ['caller' => $caller, 'collection' => 'tickets', 'id' => '2'])->assertOk()->assertJsonPath('record.subject', 'Invoice copy');
    datasourceRpc('Get', ['caller' => $caller, 'collection' => 'tickets', 'id' => '99'])->assertNotFound()->assertJsonPath('code', 'not_found');
    datasourceRpc('Get', ['caller' => $caller, 'collection' => 'nope', 'id' => '1'])->assertNotFound()->assertJsonPath('code', 'not_found');
})->with('caller');

it('writes through the handlers it has and maps business errors', function (array $caller) {
    datasourceRpc('Create', ['caller' => $caller, 'collection' => 'tickets', 'values' => ['subject' => 'New']])
        ->assertOk()->assertExactJson(['record' => ['id' => 3, 'status' => 'open', 'subject' => 'New']]);
    datasourceRpc('Create', ['caller' => $caller, 'collection' => 'tickets', 'values' => []])
        ->assertStatus(400)->assertExactJson(['code' => 'invalid_argument', 'message' => 'A subject is required']);
    datasourceRpc('Update', ['caller' => $caller, 'collection' => 'tickets', 'id' => '1', 'values' => ['status' => 'closed']])
        ->assertStatus(501)->assertJsonPath('code', 'unimplemented');
    datasourceRpc('Delete', ['caller' => $caller, 'collection' => 'tickets', 'id' => '1'])
        ->assertStatus(501)->assertJsonPath('code', 'unimplemented');
})->with('caller');

it('maps each kind of datasource error', function (string $kind, string $code, int $status) {
    Aviato::datasource()->collection('failing')->list(fn () => throw new DatasourceException('because', $kind));

    datasourceRpc('List', ['collection' => 'failing'])->assertStatus($status)->assertExactJson(['code' => $code, 'message' => 'because']);
})->with([
    ['not_found', 'not_found', 404],
    ['invalid', 'invalid_argument', 400],
    ['conflict', 'already_exists', 409],
    ['forbidden', 'permission_denied', 403],
]);

it('lists changes since a cursor, without a caller', function () {
    datasourceRpc('ListChanges', ['collection' => 'rates', 'cursor' => ''])
        ->assertOk()->assertExactJson(['records' => [['currency' => 'EUR', 'rate' => 1, 'caller' => null]], 'nextCursor' => '1', 'hasMore' => true]);
    datasourceRpc('ListChanges', ['collection' => 'rates', 'cursor' => '1'])
        ->assertOk()->assertExactJson(['deletedIds' => ['USD'], 'nextCursor' => '2']);
    datasourceRpc('ListChanges', ['collection' => 'tickets'])->assertStatus(501)->assertJsonPath('code', 'unimplemented');
});

it('answers unknown datasource methods with unimplemented and rejects unsigned calls', function () {
    datasourceRpc('Nope')->assertStatus(501)->assertJsonPath('code', 'unimplemented');
    $this->call('POST', '/aviato/aviato.plugin.v1.DatasourceService/List', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertUnauthorized();
});

it('calls datasource handlers without a connector', function () {
    $fake = Aviato::fake();

    expect($fake->listCustomRecords('tickets', ['status' => ['$eq' => 'closed']]))->toBe(['records' => [['id' => 2, 'subject' => 'Invoice copy', 'status' => 'closed']], 'total' => 1])
        ->and($this->seen[1])->toBe('u_test')
        ->and($fake->getCustomRecord('tickets', 1)['subject'])->toBe('Login broken')
        ->and($fake->createCustomRecord('tickets', ['subject' => 'Refund']))->toBe(['id' => 3, 'status' => 'open', 'subject' => 'Refund'])
        ->and($fake->listChanges('rates')['hasMore'])->toBeTrue()
        ->and(array_column($fake->customCollections(), 'name'))->toBe(['tickets', 'rates']);
    expect(fn () => $fake->getCustomRecord('tickets', 99))->toThrow(RpcException::class, 'No record 99 in tickets');
    expect(fn () => $fake->createCustomRecord('tickets', []))->toThrow(DatasourceException::class, 'A subject is required');
});

it('refuses replicated collections without listChanges', function () {
    Aviato::datasource()->replication()->collection('orphan')->list(fn () => []);
    expect(fn () => Aviato::fake()->customCollections())->toThrow(InvalidDefinitionException::class, 'listChanges');
});

it('refuses collections declared twice', function () {
    Aviato::datasource()->collection('tickets')->list(fn () => []);
    expect(fn () => Aviato::fake()->customCollections())->toThrow(InvalidDefinitionException::class, 'declared twice');
});

it('asks the connector to refresh a replica with a signed request', function () {
    Http::fake(['connector.test/*' => Http::response(['collection' => 'rates', 'upserted' => 1, 'deleted' => 0, 'cursor' => '1'])]);

    expect(Aviato::refreshReplica('rates', 'http://connector.test/plugin-data/'))->toBe(['collection' => 'rates', 'upserted' => 1, 'deleted' => 0, 'cursor' => '1']);

    Http::assertSent(function (Request $request) {
        $headers = array_map(fn (array $values) => $values[0], array_change_key_case($request->headers()));
        (new Webhook(TestCase::SECRET))->verify($request->body(), $headers);

        return $request->url() === 'http://connector.test/plugin-data/replicas/rates/refresh' && $request->method() === 'POST';
    });
});

it('reports a refused replica refresh', function () {
    Http::fake(['connector.test/*' => Http::response(['detail' => 'Unauthorized'], 401)]);

    Aviato::refreshReplica('rates', 'http://connector.test/plugin-data');
})->throws(RequestException::class);

it('distinguishes composite keys containing separators in the get fallback', function () {
    Aviato::datasource()
        ->collection('composite', fn (CustomCollectionDefinition $collection) => $collection
            ->primaryKey('a', 'b')->field('a')->field('b')->field('label'))
        ->list(fn () => ['records' => [
            ['a' => 'x|y', 'b' => 'z', 'label' => 'first'],
            ['a' => 'x', 'b' => 'y|z', 'label' => 'second'],
            ['a' => 'é|', 'b' => '', 'label' => 'unicode'],
        ]]);
    $fake = Aviato::fake();
    expect($fake->getCustomRecord('composite', '~787c79.7a')['label'])->toBe('first')
        ->and($fake->getCustomRecord('composite', '~78.797c7a')['label'])->toBe('second')
        ->and($fake->getCustomRecord('composite', '~c3a97c.')['label'])->toBe('unicode');
});
