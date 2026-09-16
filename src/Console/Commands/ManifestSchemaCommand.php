<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestManager;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ManifestSchemaCommand extends Command
{
    public const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    public const NO_SCHEMA_MESSAGE = 'The selected manifest does not define a schema.';

    public const MISSING_NAME_MESSAGE = 'Please specify a manifest name.';

    public const EMPTY_REGISTRY_MESSAGE = 'No manifest definitions are registered in this application.';

    public const NEWLINE = "\n";

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manifest:schema
                            {name? : The manifest alias name}
                            {--output= : Path to save the compiled JSON Schema}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate and display or export the JSON Schema for a registered manifest';

    public function __construct(
        protected readonly ManifestManager $manager,
        protected readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $registry = $this->manager->registry();
        $registered = array_keys($registry->all());
        $nameArgument = $this->argument('name');

        if (! is_string($nameArgument) || trim($nameArgument) === '') {
            if (empty($registered)) {
                $this->comment(self::EMPTY_REGISTRY_MESSAGE);

                return self::SUCCESS;
            }

            if ($this->input->isInteractive()) {
                /** @var string $name */
                $name = $this->choice('Select a manifest to generate JSON Schema for:', $registered);
            } else {
                $this->error(self::MISSING_NAME_MESSAGE.' Available manifests: '.implode(', ', $registered));

                return self::FAILURE;
            }
        } else {
            $name = trim($nameArgument);
        }

        $def = $registry->get($name);

        if ($def === null) {
            $this->error("No manifest registered with alias [{$name}].");
            if (! empty($registered)) {
                $this->line('<comment>Available manifests:</comment> '.implode(', ', $registered));
            }

            return self::FAILURE;
        }

        $schemaInstance = $def->resolveSchema();
        if ($schemaInstance === null) {
            $this->warn(self::NO_SCHEMA_MESSAGE);

            return self::FAILURE;
        }

        $compiled = $schemaInstance->jsonSchema();
        if ($compiled === null) {
            $this->warn(self::NO_SCHEMA_MESSAGE);

            return self::FAILURE;
        }

        $encoded = (string) json_encode($compiled, self::JSON_FLAGS);

        $outputPath = $this->option('output');
        if (is_string($outputPath) && trim($outputPath) !== '') {
            $this->files->ensureDirectoryExists(dirname($outputPath));
            $this->files->put($outputPath, $encoded.self::NEWLINE);
            $this->info("JSON Schema written to [{$outputPath}].");

            return self::SUCCESS;
        }

        $this->line($encoded);

        return self::SUCCESS;
    }
}
