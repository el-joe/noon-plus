<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\LegacyFiles;
use App\Domains\Migration\Support\MigrationContext;

/**
 * Files (MG8, plan §1 "Files", §8.4). Migrated rows point at `legacy/{old
 * path}` on the new public disk; this step copies each such file from the
 * old public disk (read only, never moved) and checks its SHA-256 after the
 * copy. A file that is missing or unreadable is quarantined (source table
 * `files:{target table}`). Contract files are copied by the contracts step.
 */
class FilesStep extends Step
{
    /**
     * Target table → path column holding `legacy/…` references.
     *
     * @var array<string, string>
     */
    public const REFERENCES = ['product_media' => 'path', 'blog_posts' => 'cover_path', 'brands' => 'logo_path', 'categories' => 'image_path', 'vendors' => 'logo_path'];

    public function name(): string
    {
        return 'files';
    }

    public function run(): void
    {
        parent::run();

        $files = new LegacyFiles;
        $copied = [];

        foreach ($this->context->targetCountries() as $code) {
            foreach (self::REFERENCES as $table => $column) {
                $paths = MigrationContext::db($code)->table($table)->where($column, 'like', 'legacy/%')->distinct()->pluck($column);

                foreach ($paths as $path) {
                    $metricTable = 'files:'.$table;
                    $this->context->count($metricTable, 'read');

                    if (isset($copied[$path])) {
                        $this->context->count($metricTable, 'already_copied');

                        continue;
                    }

                    $result = $this->context->dryRun ? ($files->read('public', substr($path, 7)) === null ? (string) $files->error : true) : $files->copy('public', substr($path, 7), 'public', $path);

                    if ($result !== true) {
                        $this->quarantine->put($metricTable, substr(sha1($path), 0, 40), 'file_'.$result, $path, $code);

                        continue;
                    }

                    $copied[$path] = true;
                    $this->context->count($metricTable, 'copied_verified');
                }

                $this->quarantine->settle('files:'.$table);
            }
        }
    }
}
