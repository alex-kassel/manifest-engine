<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Services\ManifestInspectionService;
use Illuminate\Console\Command;

class ManifestValidateCommand extends Command
{
    public const DEFAULT_PLACEHOLDER = '—';

    public const STATUS_VALID_LABEL = '<info>✔ Valid</info>';

    public const STATUS_INVALID_LABEL = '<error>✘ Invalid</error>';

    public const STATUS_MISSING_LABEL = '<comment>Missing File</comment>';

    /**
     * @var array<int, string>
     */
    public const TABLE_HEADERS = ['Manifest', 'File', 'Status', 'Errors'];

    public const EMPTY_REGISTRY_MESSAGE = 'No manifest definitions are registered in this application.';

    public const SUCCESS_ALL_VALID_MESSAGE = 'All registered manifests are valid.';

    public const ERRORS_FOUND_MESSAGE = 'One or more manifests failed validation.';

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
            $this->comment(self::EMPTY_REGISTRY_MESSAGE);

            return self::SUCCESS;
        }

        $rows = [];
        $hasFailures = false;

        foreach ($reports as $report) {
            if (! $report->exists) {
                $rows[] = [$report->name, $report->filename, self::STATUS_MISSING_LABEL, self::DEFAULT_PLACEHOLDER];
                $hasFailures = true;
            } elseif (! $report->isValid) {
                $rows[] = [$report->name, $report->filename, self::STATUS_INVALID_LABEL, $report->formattedErrors()];
                $hasFailures = true;
            } else {
                $rows[] = [$report->name, $report->filename, self::STATUS_VALID_LABEL, self::DEFAULT_PLACEHOLDER];
            }
        }

        $this->table(self::TABLE_HEADERS, $rows);

        if ($hasFailures) {
            $this->error(self::ERRORS_FOUND_MESSAGE);

            return self::FAILURE;
        }

        $this->info(self::SUCCESS_ALL_VALID_MESSAGE);

        return self::SUCCESS;
    }
}
