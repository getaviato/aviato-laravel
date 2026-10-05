<?php

namespace Aviato\Laravel\Console;

use Aviato\Laravel\Agent\AgentBinary;
use Aviato\Laravel\Agent\AgentEnvironment;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/** Runs the agent with this application's database and plugin settings. */
final class ServeCommand extends Command
{
    protected $signature = 'aviato:serve';

    protected $description = 'Run the Aviato agent next to your application';

    public function handle(AgentEnvironment $environment): int
    {
        $binary = AgentBinary::fromConfig((array) config('aviato.agent', []));
        if (! $binary->installed()) {
            $this->components->error("The agent is not installed at {$binary->path}. Run `php artisan aviato:install` first.");

            return self::FAILURE;
        }
        try {
            $variables = $environment->variables();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Starting the Aviato agent (plugin at {$variables['AVIATO_PLUGIN_URL']})…");
        $process = new Process([$binary->path], base_path(), $variables, null, null);

        return $process->run(function (string $type, string $output): void {
            $type === Process::ERR ? $this->getOutput()->getErrorStyle()->write($output) : $this->output->write($output);
        });
    }
}
