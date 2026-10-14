<?php

namespace Aviato\Laravel\Support;

/**
 * Binds Standard Webhooks signatures to the request they were made for. The scheme signs
 * `webhook-id.webhook-timestamp.body` only, so the target goes into the signed id:
 * `msg_<random>:<base64url("<METHOD> <path>")>`, where the path is relative to where the receiver
 * is mounted (`aviato.plugin.v1.PluginService/ExecuteAction`, `files/<ref>`,
 * `replicas/<collection>/refresh`). A captured request then cannot be posted to another endpoint
 * whose body has the same shape. Same format as packages/protocol/src/plugin-signing.ts.
 */
final class Signing
{
    /** A message id is remembered for both sides of the five minutes Standard Webhooks tolerates. */
    public const REPLAY_WINDOW_SECONDS = 600;

    /** Secrets that decode to fewer bytes are refused: anyone could sign with `whsec_`. */
    public const MINIMUM_SECRET_BYTES = 16;

    public static function boundMessageId(string $random, string $method, string $path): string
    {
        $path = explode('?', ltrim($path, '/'), 2)[0];

        return 'msg_'.$random.':'.rtrim(strtr(base64_encode(strtoupper($method).' '.$path), '+/', '-_'), '=');
    }

    /**
     * Whether a verified id may be used for this request. Unbound ids come from connectors that
     * predate the binding and are accepted; a bound id must name this method and a path the
     * request path ends with.
     */
    public static function targetMatches(string $id, string $method, string $requestPath): bool
    {
        $separator = strpos($id, ':');
        if ($separator === false) {
            return true;
        }
        $encoded = substr($id, $separator + 1);
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return false;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false || ! mb_check_encoding($decoded, 'UTF-8')) {
            return false;
        }
        $parts = explode(' ', $decoded, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || $parts[0] !== strtoupper($method)) {
            return false;
        }
        $requestPath = '/'.ltrim($requestPath, '/');

        return $requestPath === '/'.$parts[1] || str_ends_with($requestPath, '/'.$parts[1]);
    }

    /** Whether a whsec_<base64> secret decodes strictly to a key long enough to sign with. */
    public static function validSecret(string $secret): bool
    {
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);

        return $key !== false && strlen($key) >= self::MINIMUM_SECRET_BYTES;
    }
}
