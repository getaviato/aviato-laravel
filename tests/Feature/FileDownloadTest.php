<?php

use Aviato\Laravel\Tests\Fixtures\FixturePlugin;

beforeEach(fn () => FixturePlugin::register());

function exportFile(): string
{
    return test()->rpc('ExecuteAction', ['caller' => ['userId' => 'u1'], 'action' => 'Export', 'target' => ['collection' => 'customers']])
        ->assertOk()
        ->json('result.file.ref');
}

it('serves a generated file once', function () {
    $ref = exportFile();
    $headers = $this->serverHeaders($this->signedHeaders(''));

    $response = $this->call('GET', "/aviato/files/{$ref}", [], [], [], $headers)->assertOk();
    expect($response->getContent())->toBe("id,name\n1,Acme\n")
        ->and($response->headers->get('content-type'))->toStartWith('text/csv')
        ->and($response->headers->get('content-disposition'))->toBe('attachment; filename=customers.csv');

    $this->call('GET', "/aviato/files/{$ref}", [], [], [], $this->serverHeaders($this->signedHeaders('')))
        ->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('requires a signature to download a file', function () {
    $ref = exportFile();

    $this->get("/aviato/files/{$ref}")->assertStatus(401);
    // The failed attempt does not consume the file.
    $this->call('GET', "/aviato/files/{$ref}", [], [], [], $this->serverHeaders($this->signedHeaders('')))->assertOk();
});

it('forgets files after their time to live', function () {
    $ref = exportFile();
    $this->travel(301)->seconds();

    $this->call('GET', "/aviato/files/{$ref}", [], [], [], $this->serverHeaders($this->signedHeaders('')))->assertNotFound();
});

it('answers unknown references with not found', function () {
    $this->call('GET', '/aviato/files/00000000-0000-0000-0000-000000000000', [], [], [], $this->serverHeaders($this->signedHeaders('')))->assertNotFound();
});
