<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ManifestStatusCommand extends Command
{
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
            $this->comment('No manifest definitions are registered in this application.');

            return self::SUCCESS;
        }

        $rootPath = function_exists('base_path') ? base_path() : (string) getcwd();
        $rows = [];

        foreach ($manifests as $name => $def) {
            $manifestPath = $rootPath.DIRECTORY_SEPARATOR.$def->filename;
            $hasManifest = $this->files->exists($manifestPath);

            $runnerDisplay = '—';
            if ($def->runnerPath !== null) {
                $runnerName = basename($def->runnerPath);
                $hasRunner = $this->files->exists($rootPath.DIRECTORY_SEPARATOR.$runnerName);
                $runnerDisplay = $hasRunner ? "<info>✔ ./{$runnerName}</info>" : "<comment>missing (./{$runnerName})</comment>";
            }

            $rows[] = [
                $name,
                $def->filename,
                $hasManifest ? '<info>✔ Exists</info>' : '<comment>Missing</comment>',
                $runnerDisplay,
                $def->description ?? '—',
            ];
        }

        $this->table(['Manifest', 'File', 'Status', 'Standalone Runner', 'Description'], $rows);

        return self::SUCCESS;
    }
}
