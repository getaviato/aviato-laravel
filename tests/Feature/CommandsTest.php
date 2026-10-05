<?php

use Aviato\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/aviato-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->directory);
    config()->set('aviato.agent.path', "{$this->directory}/aviato-agent");
});

afterEach(fn () => File::deleteDirectory($this->directory));

it('downloads the agent for the current platform', function () {
    config()->set('aviato.agent.download_url', 'https://releases.test/v{version}/aviato-agent-{os}-{arch}{ext}');
    Http::fake(['releases.test/*' => Http::response('#!/bin/sh')]);

    $this->artisan('aviato:install', ['--agent-version' => '1.2.3'])->assertSuccessful();

    expect(File::get("{$this->directory}/aviato-agent"))->toBe('#!/bin/sh')
        ->and(fileperms("{$this->directory}/aviato-agent") & 0777)->toBe(0755);
    Http::assertSent(fn ($request) => preg_match('#^https://releases\.test/v1\.2\.3/aviato-agent-(linux|darwin|windows)-(x64|arm64)(\.exe)?$#', $request->url()) === 1);
});

it('verifies the checksum when configured', function () {
    config()->set('aviato.agent.download_url', 'https://releases.test/agent');
    config()->set('aviato.agent.checksum_url', '{url}.sha256');
    Http::fake([
        'releases.test/agent' => Http::response('binary'),
        'releases.test/agent.sha256' => Http::response(str_repeat('0', 64).'  aviato-agent'),
    ]);

    $this->artisan('aviato:install')->assertFailed();
    expect(File::exists("{$this->directory}/aviato-agent"))->toBeFalse();
});

it('does not download the agent twice', function () {
    File::put("{$this->directory}/aviato-agent", 'old');
    Http::fake();

    $this->artisan('aviato:install')->assertSuccessful();
    Http::assertNothingSent();
});

it('reports failed downloads', function () {
    Http::fake(['*' => Http::response('Not Found', 404)]);

    $this->artisan('aviato:install')->assertFailed();
    expect(File::exists("{$this->directory}/aviato-agent"))->toBeFalse();
});

it('runs the agent with the application settings', function () {
    File::put("{$this->directory}/aviato-agent", "#!/bin/sh\necho \"\$AVIATO_CONTROL_PLANE_URL \$AVIATO_AGENT_TOKEN \$AVIATO_DATABASE_URL \$AVIATO_PLUGIN_URL \$AVIATO_PLUGIN_SECRET \$EXTRA\"\n");
    chmod("{$this->directory}/aviato-agent", 0755);
    config()->set('app.url', 'https://shop.test');
    config()->set('aviato.agent.control_plane_url', 'https://aviato.test');
    config()->set('aviato.agent.token', 'agt_1');
    config()->set('aviato.agent.env', ['EXTRA' => 'yes']);
    config()->set('database.default', 'pg');
    config()->set('database.connections.pg', ['driver' => 'pgsql', 'host' => 'db', 'port' => 5432, 'database' => 'shop', 'username' => 'app', 'password' => 'p@ss']);

    $this->artisan('aviato:serve')
        ->expectsOutputToContain('https://aviato.test agt_1 postgres://app:p%40ss@db:5432/shop https://shop.test/aviato '.TestCase::SECRET.' yes')
        ->assertSuccessful();
})->skipOnWindows();

it('refuses to serve without an agent token', function () {
    File::put("{$this->directory}/aviato-agent", '');
    config()->set('aviato.agent.control_plane_url', 'https://aviato.test');

    $this->artisan('aviato:serve')->expectsOutputToContain('AVIATO_AGENT_TOKEN')->assertFailed();
});

it('refuses to serve before the agent is installed', function () {
    $this->artisan('aviato:serve')->expectsOutputToContain('aviato:install')->assertFailed();
});
