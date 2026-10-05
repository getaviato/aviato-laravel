<?php

namespace Aviato\Laravel;

use Aviato\Laravel\Console\InstallCommand;
use Aviato\Laravel\Console\RelationsCommand;
use Aviato\Laravel\Console\ServeCommand;
use Aviato\Laravel\Context\Caller;
use Aviato\Laravel\Data\HttpDataClient;
use Aviato\Laravel\Relations\EloquentRelationHints;
use Aviato\Laravel\Rpc\FileStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class AviatoServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('aviato')
            ->hasConfigFile()
            ->hasRoute('aviato')
            ->hasCommands([InstallCommand::class, ServeCommand::class, RelationsCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Plugin::class, function (Application $app): Plugin {
            $plugin = new Plugin(fn (Caller $caller): HttpDataClient => new HttpDataClient($app->make(Factory::class), $caller));

            return $plugin->resolveRelationHintsUsing(function () use ($app): array {
                if (! filter_var(config('aviato.relations.enabled', true), FILTER_VALIDATE_BOOL)) {
                    return [];
                }
                /** @var list<class-string<Model>> $models */
                $models = array_values((array) config('aviato.relations.models', []));

                return (new EloquentRelationHints($app))->hints($models === [] ? null : $models);
            });
        });
        $this->app->alias(Plugin::class, 'aviato');

        $this->app->singleton(FileStore::class, function (Application $app): FileStore {
            $store = config('aviato.files.cache_store');
            $ttl = config('aviato.files.ttl', 300);

            return new FileStore($app->make('cache')->store(is_string($store) && $store !== '' ? $store : null), is_numeric($ttl) ? (int) $ttl : 300);
        });
    }
}
