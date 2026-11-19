<?php

namespace App\Domains\Panels\Console;

use App\Shared\Http\OpenApi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the OpenAPI 3.1 description of `/api/v1` (QC-044), generated from
 * the routes, for client generation and API review.
 */
#[Signature('nawy:openapi {--path= : Output file (default: docs/api/openapi.json at the repository root)}')]
#[Description('Generate the OpenAPI description of the public API from the routes')]
class OpenApiCommand extends Command
{
    public function handle(OpenApi $openApi): int
    {
        $path = (string) ($this->option('path') ?: dirname(base_path()).'/docs/api/openapi.json');
        $spec = $openApi->build();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $operations = array_sum(array_map('count', $spec['paths']));
        $this->components->info(count($spec['paths'])." paths, {$operations} operations written to {$path}");

        return self::SUCCESS;
    }
}
