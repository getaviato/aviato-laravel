<?php

namespace Aviato\Laravel\Context;

/**
 * What an action returns. Build it with the static constructors, or the same helpers on
 * {@see ActionContext}.
 */
final readonly class ActionResult
{
    /** @param array<string, mixed> $data */
    private function __construct(public string $kind, public array $data) {}

    /** @param array<array-key, string> $invalidated Relations of the target record to refresh in the UI. */
    public static function success(string $message = '', array $invalidated = []): self
    {
        return new self('success', ['message' => $message, 'invalidated' => array_values($invalidated)]);
    }

    public static function error(string $message, string $html = ''): self
    {
        return new self('error', ['message' => $message, 'html' => $html]);
    }

    /** Sanitized by the dashboard before display. */
    public static function html(string $html): self
    {
        return new self('html', ['html' => $html]);
    }

    /** Downloaded by the user once through the connector. */
    public static function file(string $name, string $mimeType, string $content): self
    {
        return new self('file', ['name' => $name, 'mimeType' => $mimeType, 'content' => $content]);
    }

    /** Either an internal dashboard path or an absolute URL. */
    public static function redirect(?string $url = null, ?string $path = null): self
    {
        return new self('redirect', ['url' => $url ?? '', 'path' => $path ?? '']);
    }

    /**
     * A request performed by the user's browser (never by AI agents).
     *
     * @param  array<string, string>  $headers
     */
    public static function webhook(string $url, string $method = 'POST', array $headers = [], string $body = ''): self
    {
        return new self('webhook', ['url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $body]);
    }

    public function message(): string
    {
        return is_string($this->data['message'] ?? null) ? $this->data['message'] : '';
    }
}
