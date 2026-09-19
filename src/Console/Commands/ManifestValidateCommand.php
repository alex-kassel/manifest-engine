<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Services\ManifestInspectionService;
use Illuminate\Console\Command;

class ManifestValidateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manifest:validate
                            {name? : Specific manifest alias to validate}
                            {--base-path= : Optional custom root directory}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate registered manifest files against their schema rules';

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
        $targetName = $this->argument('name');
        $basePathOption = $this->option('base-path');
        $basePath = is_string($basePathOption) && trim($basePathOption) !== '' ? $basePathOption : null;

        try {
            $reports = $this->inspector->validateAll(
                targetName: is_string($targetName) && trim($targetName) !== '' ? trim($targetName) : null,
                basePath: $basePath,
            );
        } catch (ManifestException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (empty($reports)) {
            $this->comment('No manifest definitions are registered in this application.');

            return self::SUCCESS;
        }

        $rows = [];
        $hasFailures = false;

        foreach ($reports as $report) {
            if (! $report->exists) {
                $rows[] = [$report->name, $report->filename, '<comment>Missing File</comment>', '—'];
                $hasFailures = true;
            } elseif (! $report->isValid) {
                $rows[] = [$report->name, $report->filename, '<error>✘ Invalid</error>', $this->inspector->formatErrors($report)];
                $hasFailures = true;
            } else {
                $rows[] = [$report->name, $report->filename, '<info>✔ Valid</info>', '—'];
            }
        }

        $this->table(['Manifest', 'File', 'Status', 'Errors'], $rows);

        if ($hasFailures) {
            $this->error('One or more manifests failed validation.');

            return self::FAILURE;
        }

        $this->info('All registered manifests are valid.');

        return self::SUCCESS;
    }
}
