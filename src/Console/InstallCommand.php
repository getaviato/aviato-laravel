<?php

namespace Aviato\Laravel\Console;

use Aviato\Laravel\Agent\AgentBinary;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Throwable;

/** Publishes the configuration and downloads the agent binary for this platform. */
final class InstallCommand extends Command
{
    protected $signature = 'aviato:install
        {--agent-version= : Agent version to download instead of the configured one}
        {--force : Download the agent even when it is already installed}
        {--no-download : Only publish the configuration}';

    protected $description = 'Install the Aviato agent next to your application';

    public function handle(Factory $http): int
    {
        if (! is_file(config_path('aviato.php'))) {
            $this->callSilently('vendor:publish', ['--tag' => 'aviato-config']);
            $this->components->info('Published config/aviato.php.');
        }
        if ($this->option('no-download')) {
            return self::SUCCESS;
        }

        $config = (array) config('aviato.agent', []);
        if (is_string($this->option('agent-version')) && $this->option('agent-version') !== '') {
            $config['version'] = $this->option('agent-version');
        }
        try {
            $binary = AgentBinary::fromConfig($config);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($binary->installed() && ! $this->option('force')) {
            $this->components->info("The agent is already installed at {$binary->path} (use --force to download it again).");

            return self::SUCCESS;
        }

        $url = $binary->downloadUrl();
        $this->components->info("Downloading the Aviato agent {$binary->version} for {$binary->platform->os}-{$binary->platform->arch}…");
        File::ensureDirectoryExists(dirname($binary->path));
        $temporary = $binary->path.'.download';
        try {
            $http->timeout(600)->sink($temporary)->get($url)->throw();
            $checksumUrl = $binary->checksumUrl();
            if ($checksumUrl !== null) {
                $expected = strtolower(substr(trim($http->timeout(60)->get($checksumUrl)->throw()->body()), 0, 64));
                $actual = hash_file('sha256', $temporary);
                if (! hash_equals($expected, (string) $actual)) {
                    throw new \RuntimeException("Checksum mismatch for {$url}.");
                }
            }
        } catch (Throwable $exception) {
            File::delete($temporary);
            $this->components->error("Could not download the agent from {$url}: {$exception->getMessage()}");

            return self::FAILURE;
        }
        File::move($temporary, $binary->path);
        File::chmod($binary->path, 0755);
        $this->components->info("Installed the agent at {$binary->path}. Start it with `php artisan aviato:serve`.");

        return self::SUCCESS;
    }
}
