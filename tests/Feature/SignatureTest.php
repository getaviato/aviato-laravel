<?php

use Aviato\Laravel\Tests\Fixtures\FixturePlugin;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => FixturePlugin::register());

function manifestCall(array $headers, string $body = '{}'): TestResponse
{
    return test()->call('POST', '/aviato/aviato.plugin.v1.PluginService/GetManifest', [], [], [], test()->serverHeaders($headers), $body);
}

it('accepts requests signed with the plugin secret', function () {
    manifestCall($this->signedHeaders('{}'))->assertOk()->assertJsonPath('manifest.protocolVersion', 1);
});

it('rejects unsigned requests', function () {
    manifestCall(['content-type' => 'application/json'])
        ->assertStatus(401)
        ->assertExactJson(['code' => 'unauthenticated', 'message' => 'Invalid plugin request signature']);
});

it('rejects requests signed with another secret', function () {
    manifestCall($this->signedHeaders('{}', 'whsec_'.base64_encode('not-the-plugin-secret-at-all')))->assertStatus(401);
});

it('rejects signatures older than five minutes', function () {
    manifestCall($this->signedHeaders('{}', timestamp: time() - 301))->assertStatus(401);
});

it('rejects signatures from the future', function () {
    manifestCall($this->signedHeaders('{}', timestamp: time() + 301))->assertStatus(401);
});

it('rejects a tampered body', function () {
    manifestCall($this->signedHeaders('{}'), '{"agentProtocolVersion":2}')->assertStatus(401);
});

it('rejects malformed signature headers', function () {
    manifestCall([...$this->signedHeaders('{}'), 'webhook-signature' => 'garbage'])->assertStatus(401);
});

it('accepts one valid signature among several', function () {
    $headers = $this->signedHeaders('{}');
    $headers['webhook-signature'] = 'v1,'.base64_encode('wrong').' '.$headers['webhook-signature'];
    manifestCall($headers)->assertOk();
});

it('fails closed when no secret is configured', function () {
    config()->set('aviato.secret', null);
    manifestCall($this->signedHeaders('{}'))->assertStatus(500)->assertJsonPath('code', 'internal');
});

it('does not require a CSRF token or a session', function () {
    $response = manifestCall($this->signedHeaders('{}'))->assertOk();
    expect($response->headers->getCookies())->toBeEmpty();
});
