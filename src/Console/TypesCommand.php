<?php

namespace Aviato\Laravel\Console;

use Aviato\Laravel\Connector\ConnectorBinary;
use Aviato\Laravel\Connector\ConnectorEnvironment;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/** Generates a class per collection of the database, so plugin code names collections and fields by constant. */
final class TypesCommand extends Command
{
    protected $signature = 'aviato:types
        {--out=app/Aviato : Directory of the generated classes}
        {--namespace=App\\Aviato : Namespace of the generated classes}
        {--check : Write nothing and fail when the classes are out of date (for CI)}';

    protected $description = 'Generate collection and field constants from your database schema';

    public function handle(ConnectorEnvironment $environment): int
    {
        $binary = ConnectorBinary::fromConfig((array) array_replace((array) config('aviato.connector', []), (array) config('aviato.agent', [])));
        if (! $binary->installed()) {
            $this->components->error("The connector is not installed at {$binary->path}. Run `php artisan aviato:install` first.");

            return self::FAILURE;
        }
        $command = [$binary->path, 'typegen', '--lang', 'php', '--out', base_path($this->stringOption('out', 'app/Aviato')), '--namespace', $this->stringOption('namespace', 'App\\Aviato')];
        if ($this->option('check')) {
            $command[] = '--check';
        }
        $process = new Process($command, base_path(), $environment->databaseVariables(), null, null);

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
