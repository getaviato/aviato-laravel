<?php

namespace Aviato\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StandardWebhooks\Webhook;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Rejects requests that are not signed by the agent with the plugin secret (Standard Webhooks:
 * webhook-id, webhook-timestamp and webhook-signature headers, five minutes of tolerance).
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

        return $next($request);
    }
}
