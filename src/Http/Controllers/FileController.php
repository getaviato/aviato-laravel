<?php

namespace Aviato\Laravel\Http\Controllers;

use Aviato\Laravel\Rpc\FileStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/** GET {basePath}/files/{ref}: streams a file produced by an action, once. */
final class FileController
{
    public function __invoke(string $ref, FileStore $files): Response
    {
        $file = $files->pull($ref);
        if ($file === null) {
            return new JsonResponse(['code' => 'not_found', 'message' => 'Unknown or expired file'], 404);
        }

        return new Response($file['content'], 200, [
            'Content-Type' => $file['mimeType'],
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file['name'], str_replace(['%', '/', '\\'], '', Str::ascii($file['name'])) ?: 'download'),
            'Cache-Control' => 'no-store',
        ]);
    }
}
