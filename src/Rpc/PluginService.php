<?php

namespace Aviato\Laravel\Rpc;

use Aviato\Laravel\Context\ActionResult;
use Aviato\Laravel\Context\Caller;
use Aviato\Laravel\Enums\HookOperation;
use Aviato\Laravel\Enums\HookTiming;
use Aviato\Laravel\Exceptions\RpcException;
use Aviato\Laravel\Plugin;
use Aviato\Laravel\Support\Structs;
use Aviato\Plugin\V1\ActionResult as ProtoActionResult;
use Aviato\Plugin\V1\ActionTarget;
use Aviato\Plugin\V1\Caller as ProtoCaller;
use Aviato\Plugin\V1\ComputeChartRequest;
use Aviato\Plugin\V1\ComputeChartResponse;
use Aviato\Plugin\V1\ComputeFieldsRequest;
use Aviato\Plugin\V1\ComputeFieldsResponse;
use Aviato\Plugin\V1\ErrorResult;
use Aviato\Plugin\V1\ExecuteActionRequest;
use Aviato\Plugin\V1\ExecuteActionResponse;
use Aviato\Plugin\V1\FileResult;
use Aviato\Plugin\V1\GetManifestRequest;
use Aviato\Plugin\V1\GetManifestResponse;
use Aviato\Plugin\V1\HtmlResult;
use Aviato\Plugin\V1\RecordIds;
use Aviato\Plugin\V1\RedirectResult;
use Aviato\Plugin\V1\ResolveFormRequest;
use Aviato\Plugin\V1\ResolveFormResponse;
use Aviato\Plugin\V1\ResolveSegmentRequest;
use Aviato\Plugin\V1\ResolveSegmentResponse;
use Aviato\Plugin\V1\RunHookRequest;
use Aviato\Plugin\V1\RunHookResponse;
use Aviato\Plugin\V1\SearchRequest;
use Aviato\Plugin\V1\SearchResponse;
use Aviato\Plugin\V1\SuccessResult;
use Aviato\Plugin\V1\WebhookResult;
use Aviato\Plugin\V1\WriteFieldRequest;
use Aviato\Plugin\V1\WriteFieldResponse;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Struct;
use Throwable;

/**
 * Serves aviato.plugin.v1.PluginService with ConnectRPC's JSON encoding: decodes the request
 * message, calls the plugin and encodes the response message.
 */
final class PluginService
{
    /** @var array<string, class-string<Message>> */
    public const METHODS = [
        'GetManifest' => GetManifestRequest::class,
        'ResolveForm' => ResolveFormRequest::class,
        'ExecuteAction' => ExecuteActionRequest::class,
        'ComputeFields' => ComputeFieldsRequest::class,
        'WriteField' => WriteFieldRequest::class,
        'ResolveSegment' => ResolveSegmentRequest::class,
        'RunHook' => RunHookRequest::class,
        'Search' => SearchRequest::class,
        'ComputeChart' => ComputeChartRequest::class,
    ];

    public function __construct(private readonly Plugin $plugin, private readonly FileStore $files) {}

    /**
     * Handles one call and returns the JSON response body.
     *
     * @throws RpcException
     */
    public function handle(string $method, string $body): string
    {
        $class = self::METHODS[$method] ?? throw new RpcException('unimplemented', "Unknown method aviato.plugin.v1.PluginService/{$method}");
        $request = new $class;
        try {
            // Unknown fields are ignored so newer agents can talk to older SDKs.
            $request->mergeFromJsonString(trim($body) === '' ? '{}' : $body, true);
        } catch (Throwable $exception) {
            throw RpcException::invalidArgument("Invalid {$method} request: {$exception->getMessage()}");
        }

        return $this->dispatch($request)->serializeToJsonString();
    }

    private function dispatch(Message $request): Message
    {
        return match (true) {
            $request instanceof GetManifestRequest => new GetManifestResponse(['manifest' => $this->plugin->manifest()]),
            $request instanceof ResolveFormRequest => $this->resolveForm($request),
            $request instanceof ExecuteActionRequest => $this->executeAction($request),
            $request instanceof ComputeFieldsRequest => $this->computeFields($request),
            $request instanceof WriteFieldRequest => $this->writeField($request),
            $request instanceof ResolveSegmentRequest => $this->resolveSegment($request),
            $request instanceof RunHookRequest => $this->runHook($request),
            $request instanceof SearchRequest => new SearchResponse([
                'filter' => Structs::fromArray($this->plugin->searchFilter($request->getCollection(), $request->getQuery(), $this->caller($request->getCaller()))),
            ]),
            $request instanceof ComputeChartRequest => new ComputeChartResponse([
                'vega_lite' => Structs::fromArray($this->plugin->computeChart($request->getChart(), Structs::toArray($request->getFilter()), $this->caller($request->getCaller()))),
            ]),
            default => throw new RpcException('unimplemented', 'Unsupported request '.$request::class),
        };
    }

    private function resolveForm(ResolveFormRequest $request): ResolveFormResponse
    {
        $target = $request->getTarget() ?? new ActionTarget;
        $resolution = $this->plugin->resolveForm(
            $target->getCollection(),
            $request->getAction(),
            $this->caller($request->getCaller()),
            $this->strings($target->getRecordIds()),
            Structs::toArray($target->getFilter()),
            $target->getSegment(),
            $target->getSearch(),
            Structs::toArray($request->getValues()) ?? [],
            $request->getChangedField(),
        );
        $response = new ResolveFormResponse;
        if ($resolution->schema !== null) {
            $response->setFormSchema(Structs::fromArray($resolution->schema));
        }
        if ($resolution->uiSchema !== null) {
            $response->setFormUiSchema(Structs::fromArray($resolution->uiSchema));
        }
        if ($resolution->values !== null) {
            $response->setValues(Structs::fromArray($resolution->values));
        }

        return $response;
    }

    private function executeAction(ExecuteActionRequest $request): ExecuteActionResponse
    {
        $target = $request->getTarget() ?? new ActionTarget;
        $result = $this->plugin->executeAction(
            $target->getCollection(),
            $request->getAction(),
            $this->caller($request->getCaller()),
            $this->strings($target->getRecordIds()),
            Structs::toArray($target->getFilter()),
            $target->getSegment(),
            $target->getSearch(),
            Structs::toArray($request->getValues()) ?? [],
        );

        return new ExecuteActionResponse(['result' => $this->toProtoResult($result)]);
    }

    private function computeFields(ComputeFieldsRequest $request): ComputeFieldsResponse
    {
        $records = [];
        foreach ($request->getRecords() as $record) {
            /** @var Struct $record */
            $records[] = Structs::toArray($record) ?? [];
        }
        $values = $this->plugin->computeFields($request->getCollection(), $this->strings($request->getFields()), $records, $this->caller($request->getCaller()));

        return new ComputeFieldsResponse(['values' => array_map(fn (array $value): Struct => Structs::fromArray($value), $values)]);
    }

    private function writeField(WriteFieldRequest $request): WriteFieldResponse
    {
        $value = $request->getValue();
        $patch = $this->plugin->writeField(
            $request->getCollection(),
            $request->getField(),
            $value !== null ? Structs::fromValue($value) : null,
            Structs::toArray($request->getRecord()) ?? [],
            $this->caller($request->getCaller()),
        );

        return new WriteFieldResponse(['patch' => Structs::fromArray($patch)]);
    }

    private function resolveSegment(ResolveSegmentRequest $request): ResolveSegmentResponse
    {
        $resolved = $this->plugin->resolveSegment($request->getCollection(), $request->getSegment(), $this->caller($request->getCaller()));

        return isset($resolved['filter'])
            ? new ResolveSegmentResponse(['filter' => Structs::fromArray($resolved['filter'])])
            : new ResolveSegmentResponse(['record_ids' => new RecordIds(['ids' => $resolved['ids']])]);
    }

    private function runHook(RunHookRequest $request): RunHookResponse
    {
        $timing = HookTiming::fromProto($request->getTiming()) ?? throw RpcException::invalidArgument('Missing hook timing');
        $operation = HookOperation::fromProto($request->getOperation()) ?? throw RpcException::invalidArgument('Missing hook operation');
        $outcome = $this->plugin->runHooks(
            $request->getCollection(),
            $timing,
            $operation,
            $this->caller($request->getCaller()),
            $this->strings($request->getRecordIds()),
            Structs::toArray($request->getValues()),
            $request->getAction(),
        );
        if ($outcome->rejected()) {
            return new RunHookResponse(['reject_message' => (string) $outcome->rejectMessage]);
        }

        return new RunHookResponse($outcome->values !== null ? ['values' => Structs::fromArray($outcome->values)] : []);
    }

    private function toProtoResult(ActionResult $result): ProtoActionResult
    {
        $data = $result->data;

        return new ProtoActionResult(match ($result->kind) {
            'success' => ['success' => new SuccessResult(['message' => $data['message'], 'invalidated' => $data['invalidated']])],
            'error' => ['error' => new ErrorResult(['message' => $data['message'], 'html' => $data['html']])],
            'html' => ['html' => new HtmlResult(['html' => $data['html']])],
            'redirect' => ['redirect' => new RedirectResult(['path' => $data['path'], 'url' => $data['url']])],
            'webhook' => ['webhook' => new WebhookResult(['url' => $data['url'], 'method' => $data['method'], 'headers' => $data['headers'], 'body' => $data['body']])],
            'file' => ['file' => new FileResult([
                'ref' => $this->files->put($data['name'], $data['mimeType'], $data['content']),
                'name' => $data['name'],
                'mime_type' => $data['mimeType'],
            ])],
            default => throw RpcException::internal("Unknown action result kind {$result->kind}"),
        });
    }

    private function caller(?ProtoCaller $caller): Caller
    {
        return $caller !== null ? Caller::fromProto($caller) : throw RpcException::invalidArgument('Missing caller');
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function strings(iterable $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            $strings[] = is_scalar($value) ? (string) $value : '';
        }

        return $strings;
    }
}
