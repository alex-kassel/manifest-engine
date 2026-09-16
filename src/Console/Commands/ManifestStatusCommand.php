<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\Services\ManifestInspectionService;
use Illuminate\Console\Command;

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
        protected readonly ManifestInspectionService $inspector,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $reports = $this->inspector->getStatusReports();

        if (empty($reports)) {
            $this->comment(self::EMPTY_REGISTRY_MESSAGE);

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($reports as $report) {
            $rows[] = [
                $report->name,
                $report->filename,
                $report->exists ? self::STATUS_EXISTS_LABEL : self::STATUS_MISSING_LABEL,
                $report->humanSize ?? self::DEFAULT_PLACEHOLDER,
                $report->lastModified ?? self::DEFAULT_PLACEHOLDER,
                $report->description ?? self::DEFAULT_PLACEHOLDER,
            ];
        }

        $this->table(self::TABLE_HEADERS, $rows);

        return self::SUCCESS;
    }
}
