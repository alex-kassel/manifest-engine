<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestValidationException;
use AlexKassel\ManifestEngine\ManifestManager;
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
        protected readonly ManifestManager $manager,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $registry = $this->manager->registry();
        $targetName = $this->argument('name');

        if (is_string($targetName) && trim($targetName) !== '') {
            $definition = $registry->get($targetName);
            if ($definition === null) {
                $this->error("No manifest registered with alias [{$targetName}].");

                return self::FAILURE;
            }
            $manifests = [$targetName => $definition];
        } else {
            $manifests = $registry->all();
        }

        if (empty($manifests)) {
            $this->comment(self::EMPTY_REGISTRY_MESSAGE);

            return self::SUCCESS;
        }

        $rows = [];
        $hasFailures = false;
        $basePathOption = $this->option('base-path');
        $basePath = is_string($basePathOption) && trim($basePathOption) !== '' ? $basePathOption : null;

        foreach ($manifests as $name => $def) {
            try {
                $manifest = $this->manager->get($name, $basePath);

                if (! $manifest->exists()) {
                    $rows[] = [$name, $def->filename, self::STATUS_MISSING_LABEL, self::DEFAULT_PLACEHOLDER];
                    $hasFailures = true;

                    continue;
                }

                $data = $manifest->load(forceFresh: true);
                $manifest->validate($data);

                $rows[] = [$name, $def->filename, self::STATUS_VALID_LABEL, self::DEFAULT_PLACEHOLDER];
            } catch (ManifestValidationException $e) {
                $hasFailures = true;
                $errorMessages = [];
                foreach ($e->errors as $field => $messages) {
                    $errorMessages[] = $field.': '.implode(', ', $messages);
                }
                $rows[] = [$name, $def->filename, self::STATUS_INVALID_LABEL, implode("\n", $errorMessages)];
            } catch (ManifestException $e) {
                $hasFailures = true;
                $rows[] = [$name, $def->filename, self::STATUS_INVALID_LABEL, $e->getMessage()];
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
