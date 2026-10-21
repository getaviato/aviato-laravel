<?php

namespace Aviato\Laravel\Console;

use Aviato\Laravel\Connector\ConnectorBinary;
use Aviato\Laravel\Connector\ConnectorEnvironment;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/** Runs the connector with this application's database and plugin settings. */
final class ServeCommand extends Command
{
    protected $signature = 'aviato:serve
        {--dev : Reload the plugin manifest every second and keep the generated classes up to date (local development)}
        {--types= : Directory of the generated collection classes to keep up to date with --dev (for example app/Aviato)}
        {--namespace=App\\Aviato : Namespace of the generated classes}
        {--project= : With --dev, run your own development environment of this project, approved once in the browser (default aviato.connector.project)}
        {--login : With --project, approve this computer again instead of reusing the stored credential}
        {--tunnel : With --dev, share the connector through a temporary Cloudflare quick tunnel for MCP clients (installs cloudflared when missing)}';

    protected $description = 'Run the Aviato connector next to your application';

    public function handle(ConnectorEnvironment $environment): int
    {
        $binary = ConnectorBinary::fromConfig((array) array_replace((array) config('aviato.connector', []), (array) config('aviato.agent', [])));
        if (! $binary->installed()) {
            $this->components->error("The connector is not installed at {$binary->path}. Run `php artisan aviato:install` first.");

            return self::FAILURE;
        }
        $project = $this->option('dev') ? $this->projectOption() : null;
        if ($this->stringOptionOrNull('project') !== null && ! $this->option('dev')) {
            $this->components->error('--project runs your own development environment, so it needs --dev.');

            return self::FAILURE;
        }
        if ($this->option('tunnel') && ! $this->option('dev')) {
            $this->components->error('--tunnel opens a temporary tunnel for local development, so it needs --dev.');

            return self::FAILURE;
        }
        try {
            $variables = $environment->variables($project !== null);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Starting the Aviato connector (plugin at {$variables['AVIATO_PLUGIN_URL']})…");
        $command = [$binary->path];
        if ($this->option('dev')) {
            $command = [$binary->path, 'dev'];
            $types = $this->option('types');
            if (is_string($types) && $types !== '') {
                array_push($command, '--types', base_path($types), '--namespace', $this->stringOption('namespace', 'App\\Aviato'));
            }
            if ($project !== null) {
                // The connector signs the developer in and finds the environment's credential itself.
                array_push($command, '--project', $project, ...($this->option('login') ? ['--login'] : []));
            }
            if ($this->option('tunnel')) {
                $command[] = '--tunnel';
            }
        }
        $process = new Process($command, base_path(), $variables, null, null);

        return $process->run(function (string $type, string $output): void {
            $type === Process::ERR ? $this->getOutput()->getErrorStyle()->write($output) : $this->output->write($output);
        });
    }

    private function stringOption(string $name, string $default): string
    {
        return $this->stringOptionOrNull($name) ?? $default;
    }

    private function stringOptionOrNull(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** `--project`, or `aviato.connector.project` when no connector token is configured. */
    private function projectOption(): ?string
    {
        $option = $this->stringOptionOrNull('project');
        if ($option !== null) {
            return $option;
        }
        $connector = (array) array_replace((array) config('aviato.connector', []), (array) config('aviato.agent', []));
        $token = $connector['token'] ?? null;
        $project = $connector['project'] ?? null;

        return (! is_string($token) || trim($token) === '') && is_string($project) && trim($project) !== '' ? trim($project) : null;
    }
}
