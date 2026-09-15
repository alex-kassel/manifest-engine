<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\ManifestEngine\Services\ManifestInstaller;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ManifestStatusCommand extends Command
{
    public const DEFAULT_PLACEHOLDER = '—';

    public const STATUS_EXISTS_LABEL = '<info>✔ Exists</info>';

    public const STATUS_MISSING_LABEL = '<comment>Missing</comment>';

    public const RUNNER_EXISTS_PREFIX = '<info>✔ ./';

    public const RUNNER_EXISTS_SUFFIX = '</info>';

    public const RUNNER_MISSING_PREFIX = '<comment>missing (./';

    public const RUNNER_MISSING_SUFFIX = ')</comment>';

    /**
     * @var array<int, string>
     */
    public const TABLE_HEADERS = ['Manifest', 'File', 'Status', 'Standalone Runner', 'Description'];

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
    protected $description = 'Show registered manifests and their file/runner presence';

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

            $runnerDisplay = self::DEFAULT_PLACEHOLDER;
            if ($def->runnerPath !== null) {
                $runnerFilename = basename($def->runnerPath);
                $stubExt = ManifestInstaller::DEFAULT_STUB_EXTENSION;
                $runnerName = str_ends_with($runnerFilename, $stubExt)
                    ? substr($runnerFilename, 0, -strlen($stubExt))
                    : $runnerFilename;

                $hasRunner = $this->files->exists($rootPath.DIRECTORY_SEPARATOR.$runnerName);
                $runnerDisplay = $hasRunner
                    ? self::RUNNER_EXISTS_PREFIX.$runnerName.self::RUNNER_EXISTS_SUFFIX
                    : self::RUNNER_MISSING_PREFIX.$runnerName.self::RUNNER_MISSING_SUFFIX;
            }

            $rows[] = [
                $name,
                $def->filename,
                $hasManifest ? self::STATUS_EXISTS_LABEL : self::STATUS_MISSING_LABEL,
                $runnerDisplay,
                $def->description ?? self::DEFAULT_PLACEHOLDER,
            ];
        }

        $this->table(self::TABLE_HEADERS, $rows);

        return self::SUCCESS;
    }
}
