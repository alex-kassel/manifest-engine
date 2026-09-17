<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestManager;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ManifestSchemaCommand extends BaseManifestCommand
{
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
        ManifestManager $manager,
        protected readonly Filesystem $files,
    ) {
        parent::__construct($manager);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->resolveManifestName('Select a manifest to generate JSON Schema for:');

        if ($name === null) {
            return empty($this->registeredNames()) ? self::SUCCESS : self::FAILURE;
        }

        $def = $this->manager->registry()->get($name);

        if ($def === null) {
            $this->error("No manifest registered with alias [{$name}].");
            $registered = $this->registeredNames();
            if (! empty($registered)) {
                $this->line('<comment>Available manifests:</comment> '.implode(', ', $registered));
            }

            return self::FAILURE;
        }

        $schemaInstance = $def->resolveSchema();
        if ($schemaInstance === null) {
            $this->warn('The selected manifest does not define a schema.');

            return self::FAILURE;
        }

        $compiled = $schemaInstance->jsonSchema();
        if ($compiled === null) {
            $this->warn('The selected manifest does not define a schema.');

            return self::FAILURE;
        }

        $encoded = (string) json_encode($compiled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $outputPath = $this->option('output');
        if (is_string($outputPath) && trim($outputPath) !== '') {
            $this->files->ensureDirectoryExists(dirname($outputPath));
            $this->files->put($outputPath, $encoded."\n");
            $this->info("JSON Schema written to [{$outputPath}].");

            return self::SUCCESS;
        }

        $this->line($encoded);

        return self::SUCCESS;
    }
}
