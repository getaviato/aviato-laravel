<?php

namespace Aviato\Laravel\Http\Middleware;

use Aviato\Laravel\Support\Signing;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StandardWebhooks\Webhook;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Rejects requests that are not signed by the connector with the plugin secret (Standard Webhooks:
 * webhook-id, webhook-timestamp and webhook-signature headers, five minutes of tolerance), signed
 * for another endpoint (see Signing), or already seen: each message id is accepted once, across the
 * workers sharing the files cache store.
 */
final class VerifyPluginSignature
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('aviato.secret');
        if (! is_string($secret) || $secret === '') {
            return new JsonResponse(['code' => 'internal', 'message' => 'The Aviato plugin secret is not configured (AVIATO_PLUGIN_SECRET).'], 500);
        }
        if (! Signing::validSecret($secret)) {
            return new JsonResponse(['code' => 'internal', 'message' => 'The Aviato plugin secret (AVIATO_PLUGIN_SECRET) must be whsec_ followed by at least '.Signing::MINIMUM_SECRET_BYTES.' base64-encoded bytes.'], 500);
        }
        $headers = [];
        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $name) {
            $value = $request->headers->get($name);
            if ($value !== null) {
                $headers[$name] = $value;
            }
        }
        try {
            // File downloads are GET requests, signed with an empty body.
            (new Webhook($secret))->verify($request->isMethod('GET') ? '' : $request->getContent(), $headers);
        } catch (Throwable) {
            return new JsonResponse(['code' => 'unauthenticated', 'message' => 'Invalid plugin request signature'], 401);
        }
        $id = $headers['webhook-id'] ?? '';
        if (! Signing::targetMatches($id, $request->getMethod(), $request->getPathInfo()) || ! $this->firstSighting($id)) {
            return new JsonResponse(['code' => 'unauthenticated', 'message' => 'Invalid plugin request signature'], 401);
        }

        return $next($request);
    }

    private function firstSighting(string $id): bool
    {
        $store = config('aviato.files.cache_store');

        return cache()->store(is_string($store) && $store !== '' ? $store : null)->add('aviato:webhook-id:'.hash('sha256', $id), true, Signing::REPLAY_WINDOW_SECONDS);
    }
}
