<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Renders the docs pages once, through the HTTP kernel, so that
 * CachesDocsResponse keeps them before the first visitor asks: the
 * container's entrypoint runs this after caching the routes.
 */
#[Signature('docs:warm')]
#[Description('Render the API docs pages once so that their cached copies are ready')]
class WarmDocs extends Command
{
    public function handle(Kernel $kernel): int
    {
        foreach (['/docs/api', '/docs/api.json'] as $path) {
            $started = microtime(true);
            $request = Request::create($path);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            if ($response->getStatusCode() !== 200) {
                $this->components->error("{$path}: HTTP {$response->getStatusCode()}");

                return Command::FAILURE;
            }

            $this->components->twoColumnDetail($path, sprintf('%.2f秒', microtime(true) - $started));
        }

        return Command::SUCCESS;
    }
}
