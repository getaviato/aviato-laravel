<?php

namespace Aviato\Laravel\Data;

use Aviato\Laravel\Context\Caller;
use Aviato\Laravel\Exceptions\RpcException;
use Aviato\Laravel\Support\Structs;
use Aviato\Plugin\V1\CreateRequest;
use Aviato\Plugin\V1\CreateResponse;
use Aviato\Plugin\V1\DeleteRequest;
use Aviato\Plugin\V1\DeleteResponse;
use Aviato\Plugin\V1\GetRequest;
use Aviato\Plugin\V1\GetResponse;
use Aviato\Plugin\V1\ListRequest;
use Aviato\Plugin\V1\ListResponse;
use Aviato\Plugin\V1\UpdateRequest;
use Aviato\Plugin\V1\UpdateResponse;
use Google\Protobuf\Internal\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/** The connector's DataService, called with ConnectRPC's JSON encoding and the invocation token. */
final class HttpDataClient implements DataClient
{
    public function __construct(private readonly Factory $http, private readonly Caller $caller) {}

    public function list(string $collection, ?array $filter = null, string $sort = '', int $limit = 50, int $offset = 0, array $fields = []): array
    {
        $request = new ListRequest(['collection' => $collection, 'sort' => $sort, 'limit' => $limit, 'offset' => $offset, 'fields' => $fields]);
        if ($filter !== null) {
            $request->setFilter(Structs::fromArray($filter));
        }
        $response = $this->call('List', $request, new ListResponse);
        $records = [];
        foreach ($response->getRecords() as $record) {
            $records[] = Structs::toArray($record) ?? [];
        }

        return ['records' => $records, 'total' => (int) $response->getTotal()];
    }

    public function get(string $collection, string $id): ?array
    {
        try {
            return Structs::toArray($this->call('Get', new GetRequest(['collection' => $collection, 'id' => $id]), new GetResponse)->getRecord());
        } catch (RpcException $exception) {
            if ($exception->errorCode === 'not_found') {
                return null;
            }
            throw $exception;
        }
    }

    public function create(string $collection, array $values): array
    {
        $request = new CreateRequest(['collection' => $collection, 'values' => Structs::fromArray($values)]);

        return Structs::toArray($this->call('Create', $request, new CreateResponse)->getRecord()) ?? [];
    }

    public function update(string $collection, string $id, array $values): array
    {
        $request = new UpdateRequest(['collection' => $collection, 'id' => $id, 'values' => Structs::fromArray($values)]);

        return Structs::toArray($this->call('Update', $request, new UpdateResponse)->getRecord()) ?? [];
    }

    public function delete(string $collection, string $id): void
    {
        $this->call('Delete', new DeleteRequest(['collection' => $collection, 'id' => $id]), new DeleteResponse);
    }

    /**
     * @template T of Message
     *
     * @param  T  $response
     * @return T
     */
    private function call(string $method, Message $request, Message $response): Message
    {
        if ($this->caller->dataUrl === '') {
            throw RpcException::failedPrecondition('The call has no Data API URL; records are only available while the connector calls the plugin.');
        }
        $url = rtrim($this->caller->dataUrl, '/').'/aviato.plugin.v1.DataService/'.$method;
        try {
            $result = $this->http
                ->withToken($this->caller->invocationToken)
                ->accept('application/json')
                ->withBody($request->serializeToJsonString(), 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            throw new RpcException('unavailable', "The connector's Data API is unreachable: {$exception->getMessage()}", $exception);
        }
        if (! $result->successful()) {
            $error = $result->json();
            $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : 'unknown';
            $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : "Data API {$method} failed with HTTP {$result->status()}";
            throw new RpcException($code, $message);
        }
        $response->mergeFromJsonString($result->body() === '' ? '{}' : $result->body(), true);

        return $response;
    }
}
