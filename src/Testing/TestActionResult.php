<?php

namespace Aviato\Laravel\Testing;

use Aviato\Laravel\Context\ActionResult;
use PHPUnit\Framework\Assert;

/** An action result with assertions, returned by {@see PluginFake::executeAction()}. */
final readonly class TestActionResult
{
    public function __construct(public ActionResult $result) {}

    public function assertSuccess(?string $message = null): self
    {
        return $this->assertKind('success')->assertField('message', $message);
    }

    public function assertError(?string $message = null): self
    {
        return $this->assertKind('error')->assertField('message', $message);
    }

    public function assertHtml(?string $html = null): self
    {
        return $this->assertKind('html')->assertField('html', $html);
    }

    public function assertFile(?string $name = null, ?string $content = null, ?string $mimeType = null): self
    {
        return $this->assertKind('file')->assertField('name', $name)->assertField('content', $content)->assertField('mimeType', $mimeType);
    }

    public function assertRedirect(?string $url = null, ?string $path = null): self
    {
        return $this->assertKind('redirect')->assertField('url', $url)->assertField('path', $path);
    }

    public function assertWebhook(?string $url = null, ?string $method = null): self
    {
        return $this->assertKind('webhook')->assertField('url', $url)->assertField('method', $method);
    }

    /** @param list<string> $relations */
    public function assertInvalidated(array $relations): self
    {
        return $this->assertKind('success')->assertField('invalidated', $relations);
    }

    public function kind(): string
    {
        return $this->result->kind;
    }

    public function get(string $key): mixed
    {
        return $this->result->data[$key] ?? null;
    }

    private function assertKind(string $kind): self
    {
        Assert::assertSame($kind, $this->result->kind, "Expected a {$kind} result, got {$this->result->kind}: ".json_encode($this->result->data));

        return $this;
    }

    private function assertField(string $key, mixed $expected): self
    {
        if ($expected !== null) {
            Assert::assertSame($expected, $this->result->data[$key] ?? null, "Unexpected {$this->result->kind} {$key}.");
        }

        return $this;
    }
}
