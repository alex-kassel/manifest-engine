<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ManifestStatusCommand extends Command
{
    public const DEFAULT_PLACEHOLDER = '—';

    public const STATUS_EXISTS_LABEL = '<info>✔ Exists</info>';

    public const STATUS_MISSING_LABEL = '<comment>Missing</comment>';

    /**
     * @var array<int, string>
     */
    public const TABLE_HEADERS = ['Manifest', 'File', 'Status', 'Size', 'Last Modified', 'Description'];

    public const EMPTY_REGISTRY_MESSAGE = 'No manifest definitions are registered in this application.';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manifest:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show registered manifests and their file presence';

    public function __construct(
        protected readonly ManifestRegistry $registry,
        protected readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $manifests = $this->registry->all();

        if (empty($manifests)) {
            $this->comment(self::EMPTY_REGISTRY_MESSAGE);

            return self::SUCCESS;
        }

        $rootPath = function_exists('base_path') ? base_path() : (string) getcwd();
        $rows = [];

        foreach ($manifests as $name => $def) {
            $manifestPath = $rootPath.DIRECTORY_SEPARATOR.$def->filename;
            $hasManifest = $this->files->exists($manifestPath);

            $size = $hasManifest ? $this->formatBytes((int) $this->files->size($manifestPath)) : self::DEFAULT_PLACEHOLDER;
            $lastModified = $hasManifest ? date('Y-m-d H:i:s', (int) $this->files->lastModified($manifestPath)) : self::DEFAULT_PLACEHOLDER;

            $rows[] = [
                $name,
                $def->filename,
                $hasManifest ? self::STATUS_EXISTS_LABEL : self::STATUS_MISSING_LABEL,
                $size,
                $lastModified,
                $def->description ?? self::DEFAULT_PLACEHOLDER,
            ];
        }

        $this->table(self::TABLE_HEADERS, $rows);

        return self::SUCCESS;
    }

    /**
     * Format bytes into a human-readable string.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $kb = round($bytes / 1024, 1);

        return $kb.' KB';
    }
}
