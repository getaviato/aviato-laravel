<?php

namespace Aviato\Laravel\Http\Controllers;

use Aviato\Laravel\Exceptions\RpcException;
use Aviato\Laravel\Rpc\DatasourceService;
use Aviato\Laravel\Rpc\PluginService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * POST {basePath}/aviato.plugin.v1.{PluginService|DatasourceService}/{method}, ConnectRPC unary
 * JSON.
 */
final class RpcController
{
    public function __invoke(Request $request, Container $container, string $service, string $method): JsonResponse
    {
        $contentType = strtolower((string) $request->headers->get('content-type', 'application/json'));
        if (! str_starts_with($contentType, 'application/json')) {
            return $this->error(new RpcException('unimplemented', 'Only the ConnectRPC JSON encoding (application/json) is supported.'), 415);
        }
        try {
            $handler = $service === 'DatasourceService' ? $container->make(DatasourceService::class) : $container->make(PluginService::class);

            return JsonResponse::fromJsonString($handler->handle($method, $request->getContent()));
        } catch (Throwable $exception) {
            return $this->error($this->toRpcException($exception));
        }
    }

    private function toRpcException(Throwable $exception): RpcException
    {
        return match (true) {
            $exception instanceof RpcException => $exception,
            $exception instanceof ValidationException => RpcException::invalidArgument($exception->getMessage()),
            $exception instanceof AuthorizationException => RpcException::permissionDenied($exception->getMessage()),
            $exception instanceof ModelNotFoundException => RpcException::notFound($exception->getMessage()),
            default => $this->internal($exception),
        };
    }

    private function internal(Throwable $exception): RpcException
    {
        report($exception);

        return RpcException::internal(config('app.debug') ? $exception->getMessage() : 'Internal error in the Aviato plugin', $exception);
    }

    private function error(RpcException $exception, ?int $status = null): JsonResponse
    {
        return new JsonResponse($exception->toConnectError(), $status ?? $exception->httpStatus());
    }
}
