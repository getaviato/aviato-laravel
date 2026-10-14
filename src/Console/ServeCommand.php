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
        {--namespace=App\\Aviato : Namespace of the generated classes}';

    protected $description = 'Run the Aviato connector next to your application';

    public function handle(ConnectorEnvironment $environment): int
    {
        $binary = ConnectorBinary::fromConfig((array) array_replace((array) config('aviato.connector', []), (array) config('aviato.agent', [])));
        if (! $binary->installed()) {
            $this->components->error("The connector is not installed at {$binary->path}. Run `php artisan aviato:install` first.");

            return self::FAILURE;
        }
        try {
            $variables = $environment->variables();
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
        }
        $process = new Process($command, base_path(), $variables, null, null);

        return $process->run(function (string $type, string $output): void {
            $type === Process::ERR ? $this->getOutput()->getErrorStyle()->write($output) : $this->output->write($output);
        });
    }

    private function stringOption(string $name, string $default): string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
