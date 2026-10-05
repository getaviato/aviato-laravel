<?php

use Aviato\Laravel\Http\Controllers\FileController;
use Aviato\Laravel\Http\Controllers\RpcController;
use Aviato\Laravel\Http\Middleware\VerifyPluginSignature;
use Illuminate\Support\Facades\Route;

// Registered outside the `web` group: the agent authenticates with request signatures, not
// sessions, so there is no CSRF token to check.
Route::prefix(trim((string) config('aviato.base_path', 'aviato'), '/'))
    ->middleware([VerifyPluginSignature::class, ...(array) config('aviato.middleware', [])])
    ->name('aviato.')
    ->group(function (): void {
        Route::post('aviato.plugin.v1.{service}/{method}', RpcController::class)
            ->where('service', 'PluginService|DatasourceService')
            ->where('method', '[A-Za-z]+')
            ->name('rpc');
        Route::get('files/{ref}', FileController::class)
            ->where('ref', '[A-Za-z0-9-]+')
            ->name('files');
    });
