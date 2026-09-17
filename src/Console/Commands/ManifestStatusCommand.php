<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\Services\ManifestInspectionService;
use Illuminate\Console\Command;

class ManifestStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manifest:status
                            {--base-path= : Optional custom root directory}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show registered manifests and their file presence';

    public function __construct(
        protected readonly ManifestInspectionService $inspector,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $basePathOption = $this->option('base-path');
        $basePath = is_string($basePathOption) && trim($basePathOption) !== '' ? trim($basePathOption) : null;

        $reports = $this->inspector->getStatusReports($basePath);

        if (empty($reports)) {
            $this->comment('No manifest definitions are registered in this application.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($reports as $report) {
            $rows[] = [
                $report->name,
                $report->filename,
                $report->exists ? '<info>✔ Exists</info>' : '<comment>Missing</comment>',
                $report->humanSize ?? '—',
                $report->lastModified ?? '—',
                $report->description ?? '—',
            ];
        }

        $this->table(['Manifest', 'File', 'Status', 'Size', 'Last Modified', 'Description'], $rows);

        return self::SUCCESS;
    }
}
