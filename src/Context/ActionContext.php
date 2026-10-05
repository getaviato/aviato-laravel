<?php

namespace Aviato\Laravel\Context;

/** Passed to an action's `execute` handler; build the result with its helpers. */
final class ActionContext extends TargetContext
{
    /** @param list<string> $invalidated Relations of the target record to refresh in the UI. */
    public function success(string $message = '', array $invalidated = []): ActionResult
    {
        return ActionResult::success($message, $invalidated);
    }

    public function error(string $message, string $html = ''): ActionResult
    {
        return ActionResult::error($message, $html);
    }

    public function html(string $html): ActionResult
    {
        return ActionResult::html($html);
    }

    public function file(string $name, string $mimeType, string $content): ActionResult
    {
        return ActionResult::file($name, $mimeType, $content);
    }

    public function redirect(?string $url = null, ?string $path = null): ActionResult
    {
        return ActionResult::redirect($url, $path);
    }

    /** @param array<string, string> $headers */
    public function webhook(string $url, string $method = 'POST', array $headers = [], string $body = ''): ActionResult
    {
        return ActionResult::webhook($url, $method, $headers, $body);
    }
}
