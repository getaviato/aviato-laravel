<?php

use Aviato\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/aviato-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->directory);
    config()->set('aviato.connector.path', "{$this->directory}/aviato-connector");
});

afterEach(fn () => File::deleteDirectory($this->directory));

it('downloads the connector for the current platform', function () {
    config()->set('aviato.connector.download_url', 'https://releases.test/v{version}/aviato-connector-{os}-{arch}{ext}');
    Http::fake(['releases.test/*' => Http::response('#!/bin/sh')]);

    $this->artisan('aviato:install', ['--agent-version' => '1.2.3'])->assertSuccessful();

    expect(File::get("{$this->directory}/aviato-connector"))->toBe('#!/bin/sh')
        ->and(fileperms("{$this->directory}/aviato-connector") & 0777)->toBe(0755);
    Http::assertSent(fn ($request) => preg_match('#^https://releases\.test/v1\.2\.3/aviato-connector-(linux|darwin|windows)-(x64|arm64)(\.exe)?$#', $request->url()) === 1);
});

it('verifies the checksum when configured', function () {
    config()->set('aviato.connector.download_url', 'https://releases.test/connector');
    config()->set('aviato.connector.checksum_url', '{url}.sha256');
    Http::fake([
        'releases.test/connector' => Http::response('binary'),
        'releases.test/connector.sha256' => Http::response(str_repeat('0', 64).'  aviato-connector'),
    ]);

    $this->artisan('aviato:install')->assertFailed();
    expect(File::exists("{$this->directory}/aviato-connector"))->toBeFalse();
});

it('does not download the connector twice', function () {
    File::put("{$this->directory}/aviato-connector", 'old');
    Http::fake();

    $this->artisan('aviato:install')->assertSuccessful();
    Http::assertNothingSent();
});

it('reports failed downloads', function () {
    Http::fake(['*' => Http::response('Not Found', 404)]);

    $this->artisan('aviato:install')->assertFailed();
    expect(File::exists("{$this->directory}/aviato-connector"))->toBeFalse();
});

it('runs the connector with the application settings', function () {
    File::put("{$this->directory}/aviato-connector", "#!/bin/sh\necho \"\$AVIATO_CONTROL_PLANE_URL \$AVIATO_CONNECTOR_TOKEN \$AVIATO_DATABASE_URL \$AVIATO_PLUGIN_URL \$AVIATO_PLUGIN_SECRET \$EXTRA\"\n");
    chmod("{$this->directory}/aviato-connector", 0755);
    config()->set('app.url', 'https://shop.test');
    config()->set('aviato.connector.control_plane_url', 'https://aviato.test');
    config()->set('aviato.connector.token', 'agt_1');
    config()->set('aviato.connector.env', ['EXTRA' => 'yes']);
    config()->set('database.default', 'pg');
    config()->set('database.connections.pg', ['driver' => 'pgsql', 'host' => 'db', 'port' => 5432, 'database' => 'shop', 'username' => 'app', 'password' => 'p@ss']);

    $this->artisan('aviato:serve')
        ->expectsOutputToContain('https://aviato.test agt_1 postgres://app:p%40ss@db:5432/shop https://shop.test/aviato '.TestCase::SECRET.' yes')
        ->assertSuccessful();
})->skipOnWindows();

it('runs the connector in development mode, keeping the generated classes up to date', function () {
    File::put("{$this->directory}/aviato-connector", "#!/bin/sh\necho \"args: \$*\"\n");
    chmod("{$this->directory}/aviato-connector", 0755);
    config()->set('aviato.connector.control_plane_url', 'https://aviato.test');
    config()->set('aviato.connector.token', 'agt_1');
    config()->set('aviato.connector.database_url', 'postgres://localhost/shop');

    $this->artisan('aviato:serve', ['--dev' => true, '--types' => 'app/Aviato'])
        ->expectsOutputToContain('args: dev --types '.base_path('app/Aviato').' --namespace App\\Aviato')
        ->assertSuccessful();
})->skipOnWindows();

it('runs the developer\'s own development environment without a token', function () {
    File::put("{$this->directory}/aviato-connector", "#!/bin/sh\necho \"[\$AVIATO_CONNECTOR_TOKEN] [\$AVIATO_CONTROL_PLANE_URL] args: \$*\"\n");
    chmod("{$this->directory}/aviato-connector", 0755);
    config()->set('aviato.connector.database_url', 'postgres://localhost/shop');

    $this->artisan('aviato:serve', ['--dev' => true, '--project' => 'prj_1', '--login' => true])
        ->expectsOutputToContain('[] [] args: dev --project prj_1 --login')
        ->assertSuccessful();

    config()->set('aviato.connector.project', 'prj_2');
    $this->artisan('aviato:serve', ['--dev' => true])->expectsOutputToContain('args: dev --project prj_2')->assertSuccessful();
})->skipOnWindows();

it('keeps a configured token over aviato.connector.project, and needs --dev for --project', function () {
    File::put("{$this->directory}/aviato-connector", "#!/bin/sh\necho \"[\$AVIATO_CONNECTOR_TOKEN] args: \$*\"\n");
    chmod("{$this->directory}/aviato-connector", 0755);
    config()->set('aviato.connector.control_plane_url', 'https://aviato.test');
    config()->set('aviato.connector.token', 'agt_1');
    config()->set('aviato.connector.project', 'prj_2');
    config()->set('aviato.connector.database_url', 'postgres://localhost/shop');

    $this->artisan('aviato:serve', ['--dev' => true])->expectsOutputToContain('[agt_1] args: dev')->doesntExpectOutputToContain('--project')->assertSuccessful();
    $this->artisan('aviato:serve', ['--project' => 'prj_1'])->expectsOutputToContain('needs --dev')->assertFailed();
})->skipOnWindows();

it('shares the development connector through a tunnel, only with --dev', function () {
    File::put("{$this->directory}/aviato-connector", "#!/bin/sh\necho \"args: \$*\"\n");
    chmod("{$this->directory}/aviato-connector", 0755);
    config()->set('aviato.connector.database_url', 'postgres://localhost/shop');

    $this->artisan('aviato:serve', ['--dev' => true, '--project' => 'prj_1', '--tunnel' => true])->expectsOutputToContain('args: dev --project prj_1 --tunnel')->assertSuccessful();
    $this->artisan('aviato:serve', ['--tunnel' => true])->expectsOutputToContain('needs --dev')->assertFailed();
})->skipOnWindows();

it('generates collection classes from the database without a project connection', function () {
    File::put("{$this->directory}/aviato-connector", "#!/bin/sh\necho \"\$AVIATO_DATABASE_URL [\$AVIATO_CONNECTOR_TOKEN] args: \$*\"\n");
    chmod("{$this->directory}/aviato-connector", 0755);
    config()->set('database.default', 'pg');
    config()->set('database.connections.pg', ['driver' => 'pgsql', 'host' => 'db', 'port' => 5432, 'database' => 'shop', 'username' => 'app', 'password' => 'secret']);

    $this->artisan('aviato:types', ['--namespace' => 'Shop\\Aviato', '--check' => true])
        ->expectsOutputToContain('postgres://app:secret@db:5432/shop [] args: typegen --lang php --out '.base_path('app/Aviato').' --namespace Shop\\Aviato --check')
        ->assertSuccessful();
})->skipOnWindows();

it('refuses to serve without a connector token', function () {
    File::put("{$this->directory}/aviato-connector", '');
    config()->set('aviato.connector.control_plane_url', 'https://aviato.test');

    $this->artisan('aviato:serve')->expectsOutputToContain('AVIATO_CONNECTOR_TOKEN')->assertFailed();
});

it('refuses to serve before the connector is installed', function () {
    $this->artisan('aviato:serve')->expectsOutputToContain('aviato:install')->assertFailed();
});

it('runs an existing binary with published legacy configuration', function () {
    $path = "{$this->directory}/aviato-agent";
    File::put($path, "#!/bin/sh\necho legacy-connector \$AVIATO_AGENT_TOKEN\n");
    chmod($path, 0755);
    config()->set('aviato.agent', [
        'path' => $path,
        'control_plane_url' => 'https://aviato.test',
        'token' => 'legacy-token',
        'database_url' => 'postgres://localhost/shop',
    ]);
    $this->artisan('aviato:serve')->expectsOutputToContain('legacy-connector legacy-token')->assertSuccessful();
})->skipOnWindows();
