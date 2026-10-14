<?php

namespace Aviato\Laravel\Tests;

use Aviato\Laravel\AviatoServiceProvider;
use Aviato\Laravel\Facades\Aviato;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use StandardWebhooks\Webhook;

abstract class TestCase extends Orchestra
{
    public const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    protected function getPackageProviders($app): array
    {
        return [AviatoServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Aviato' => Aviato::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('aviato.secret', self::SECRET);
        // Do not inherit a config published by a previous Testbench run.
        $app['config']->set('aviato.agent', []);
        $app['config']->set('cache.default', 'array');
    }

    /**
     * Standard Webhooks headers for a body, signed like the connector does.
     *
     * @return array<string, string>
     */
    public function signedHeaders(string $body, ?string $secret = null, ?int $timestamp = null, ?string $id = null): array
    {
        $id ??= 'msg_'.bin2hex(random_bytes(8));
        $timestamp ??= time();

        return [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => (new Webhook($secret ?? self::SECRET))->sign($id, $timestamp, $body),
            'content-type' => 'application/json',
        ];
    }

    /**
     * Calls a PluginService method over HTTP with a signed ConnectRPC JSON body.
     *
     * @param  array<string, mixed>  $request
     */
    public function rpc(string $method, array $request = []): TestResponse
    {
        $body = $request === [] ? '{}' : (string) json_encode($request);

        return $this->call('POST', "/aviato/aviato.plugin.v1.PluginService/{$method}", [], [], [], $this->serverHeaders($this->signedHeaders($body)), $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : "HTTP_{$key}"] = $value;
        }

        return $server;
    }
}
