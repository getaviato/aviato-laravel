<?php

namespace Aviato\Laravel\Rpc;

use Aviato\Laravel\Context\Caller;
use Aviato\Laravel\Context\DatasourceQuery;
use Aviato\Laravel\Exceptions\RpcException;
use Aviato\Laravel\Plugin;
use Aviato\Laravel\Support\Structs;
use Aviato\Plugin\V1\Caller as ProtoCaller;
use Aviato\Plugin\V1\DatasourceServiceCreateRequest;
use Aviato\Plugin\V1\DatasourceServiceCreateResponse;
use Aviato\Plugin\V1\DatasourceServiceDeleteRequest;
use Aviato\Plugin\V1\DatasourceServiceDeleteResponse;
use Aviato\Plugin\V1\DatasourceServiceGetRequest;
use Aviato\Plugin\V1\DatasourceServiceGetResponse;
use Aviato\Plugin\V1\DatasourceServiceListRequest;
use Aviato\Plugin\V1\DatasourceServiceListResponse;
use Aviato\Plugin\V1\DatasourceServiceUpdateRequest;
use Aviato\Plugin\V1\DatasourceServiceUpdateResponse;
use Aviato\Plugin\V1\DescribeCollectionsRequest;
use Aviato\Plugin\V1\ListChangesRequest;
use Aviato\Plugin\V1\ListChangesResponse;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Struct;
use Throwable;

/**
 * Serves aviato.plugin.v1.DatasourceService (custom datasources) with ConnectRPC's JSON
 * encoding: decodes the request message, calls the datasource handlers and encodes the response.
 */
final class DatasourceService
{
    /** @var array<string, class-string<Message>> */
    public const METHODS = [
        'DescribeCollections' => DescribeCollectionsRequest::class,
        'List' => DatasourceServiceListRequest::class,
        'Get' => DatasourceServiceGetRequest::class,
        'Create' => DatasourceServiceCreateRequest::class,
        'Update' => DatasourceServiceUpdateRequest::class,
        'Delete' => DatasourceServiceDeleteRequest::class,
        'ListChanges' => ListChangesRequest::class,
    ];

    public function __construct(private readonly Plugin $plugin) {}

    /**
     * Handles one call and returns the JSON response body.
     *
     * @throws RpcException
     */
    public function handle(string $method, string $body): string
    {
        $class = self::METHODS[$method] ?? throw new RpcException('unimplemented', "Unknown method aviato.plugin.v1.DatasourceService/{$method}");
        $request = new $class;
        try {
            // Unknown fields are ignored so newer connectors can talk to older SDKs.
            $request->mergeFromJsonString(trim($body) === '' ? '{}' : $body, true);
        } catch (Throwable $exception) {
            throw RpcException::invalidArgument("Invalid {$method} request: {$exception->getMessage()}");
        }

        return $this->dispatch($request)->serializeToJsonString();
    }

    private function dispatch(Message $request): Message
    {
        $datasources = $this->plugin->datasources();

        return match (true) {
            $request instanceof DescribeCollectionsRequest => $datasources->describe(),
            $request instanceof DatasourceServiceListRequest => $this->list($request),
            $request instanceof DatasourceServiceGetRequest => new DatasourceServiceGetResponse([
                'record' => Structs::fromArray($datasources->get($request->getCollection(), $request->getId(), $this->caller($request->getCaller()))),
            ]),
            $request instanceof DatasourceServiceCreateRequest => new DatasourceServiceCreateResponse([
                'record' => Structs::fromArray($datasources->create($request->getCollection(), Structs::toArray($request->getValues()) ?? [], $this->caller($request->getCaller()))),
            ]),
            $request instanceof DatasourceServiceUpdateRequest => new DatasourceServiceUpdateResponse([
                'record' => Structs::fromArray($datasources->update($request->getCollection(), $request->getId(), Structs::toArray($request->getValues()) ?? [], $this->caller($request->getCaller()))),
            ]),
            $request instanceof DatasourceServiceDeleteRequest => $this->delete($request),
            $request instanceof ListChangesRequest => $this->listChanges($request),
            default => throw new RpcException('unimplemented', 'Unsupported request '.$request::class),
        };
    }

    private function list(DatasourceServiceListRequest $request): DatasourceServiceListResponse
    {
        $fields = [];
        foreach ($request->getFields() as $field) {
            $fields[] = $field;
        }
        $limit = (int) $request->getLimit();
        $page = $this->plugin->datasources()->list($request->getCollection(), new DatasourceQuery(
            filter: Structs::toArray($request->getFilter()),
            sort: DatasourceQuery::parseSort($request->getSort()),
            limit: $limit > 0 ? $limit : null,
            offset: (int) $request->getOffset(),
            fields: $fields,
            includeTotal: $request->getIncludeTotal(),
        ), $this->caller($request->getCaller()));

        return new DatasourceServiceListResponse([
            'records' => array_map(fn (array $record): Struct => Structs::fromArray($record), $page['records']),
            'total' => $page['total'],
        ]);
    }

    private function delete(DatasourceServiceDeleteRequest $request): DatasourceServiceDeleteResponse
    {
        $this->plugin->datasources()->delete($request->getCollection(), $request->getId(), $this->caller($request->getCaller()));

        return new DatasourceServiceDeleteResponse;
    }

    private function listChanges(ListChangesRequest $request): ListChangesResponse
    {
        $changes = $this->plugin->datasources()->listChanges($request->getCollection(), $request->getCursor());

        return new ListChangesResponse([
            'records' => array_map(fn (array $record): Struct => Structs::fromArray($record), $changes['records']),
            'deleted_ids' => $changes['deletedIds'],
            'next_cursor' => $changes['nextCursor'],
            'has_more' => $changes['hasMore'],
        ]);
    }

    /** The caller, or null for the connector's own calls (no user and no request). */
    private function caller(?ProtoCaller $caller): ?Caller
    {
        if ($caller === null || ($caller->getUserId() === '' && $caller->getRequestId() === '')) {
            return null;
        }

        return Caller::fromProto($caller);
    }
}
