<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Storage;

use AlexKassel\ManifestEngine\Contracts\StorageDriver;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use Illuminate\Filesystem\Filesystem;

class AtomicFileStorage implements StorageDriver
{
    public const DEFAULT_LOCKS_DIR_SUBFOLDER = 'manifest-locks';

    public const DEFAULT_STAT_SIZE = 0;

    public const DEFAULT_EMPTY_CONTENT = '';

    public const TEMP_FILE_PREFIX = '.tmp.';

    public const LOCK_FILE_PREFIX = '.';

    public const LOCK_FILE_SUFFIX = '.lock';

    public const FILE_OPEN_MODE_WRITE = 'w';

    public const FILE_OPEN_MODE_LOCK = 'c+';

    public function __construct(
        protected readonly Filesystem $files = new Filesystem,
        protected ?string $locksDirectory = null,
    ) {
        $this->locksDirectory = $locksDirectory ?? (function_exists('storage_path')
            ? storage_path('framework'.DIRECTORY_SEPARATOR.self::DEFAULT_LOCKS_DIR_SUBFOLDER)
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.self::DEFAULT_LOCKS_DIR_SUBFOLDER);
    }

    /**
     * Determine if a manifest file exists.
     */
    public function exists(string $path): bool
    {
        return $this->files->exists($path);
    }

    /**
     * Calculate SHA-1 hash of the file on disk.
     */
    public function hash(string $path): ?string
    {
        if (! $this->exists($path)) {
            return null;
        }

        return sha1((string) $this->files->get($path));
    }

    /**
     * Read the entire content of a manifest file with a shared lock (LOCK_SH).
     *
     * @throws ManifestException
     */
    public function readLocked(string $path): string
    {
        if (! $this->exists($path)) {
            return self::DEFAULT_EMPTY_CONTENT;
        }

        $lockPath = $this->lockPath($path);
        $this->files->ensureDirectoryExists(dirname($lockPath));

        $lockFp = fopen($lockPath, self::FILE_OPEN_MODE_LOCK);
        if (! $lockFp) {
            throw new ManifestException("Failed to open lock file [{$lockPath}] for reading.");
        }

        try {
            if (! flock($lockFp, LOCK_SH)) {
                throw new ManifestException("Failed to acquire shared lock on manifest [{$path}].");
            }

            return (string) $this->files->get($path);
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Execute an atomic mutation under an exclusive lock (LOCK_EX).
     *
     * @param  callable(string): string  $callback
     *
     * @throws ManifestException
     */
    public function mutateLocked(string $path, callable $callback): string
    {
        $directory = dirname($path);
        $this->files->ensureDirectoryExists($directory);

        $lockPath = $this->lockPath($path);
        $lockFp = fopen($lockPath, self::FILE_OPEN_MODE_LOCK);
        if (! $lockFp) {
            throw new ManifestException("Failed to open lock file [{$lockPath}] for mutation.");
        }

        try {
            if (! flock($lockFp, LOCK_EX)) {
                throw new ManifestException("Failed to acquire exclusive lock on manifest [{$path}].");
            }

            $current = $this->exists($path) ? (string) $this->files->get($path) : self::DEFAULT_EMPTY_CONTENT;
            $newContent = $callback($current);

            $this->writeAtomicDirect($path, $newContent);

            return $newContent;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Atomically write contents to a file using temporary file and atomic rename under exclusive lock.
     *
     * @throws ManifestException
     */
    public function writeAtomic(string $path, string $contents): void
    {
        $directory = dirname($path);
        $this->files->ensureDirectoryExists($directory);

        $lockPath = $this->lockPath($path);
        $lockFp = fopen($lockPath, self::FILE_OPEN_MODE_LOCK);
        if (! $lockFp) {
            throw new ManifestException("Failed to open lock file [{$lockPath}] for atomic write.");
        }

        try {
            if (! flock($lockFp, LOCK_EX)) {
                throw new ManifestException("Failed to acquire exclusive lock on manifest [{$path}].");
            }

            $this->writeAtomicDirect($path, $contents);
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Write contents to a temporary file and atomically rename over the target path.
     *
     * @throws ManifestException
     */
    protected function writeAtomicDirect(string $path, string $contents): void
    {
        $directory = dirname($path);
        $this->files->ensureDirectoryExists($directory);

        $tempPath = $directory.DIRECTORY_SEPARATOR.basename($path).self::TEMP_FILE_PREFIX.uniqid('', true);

        $fp = fopen($tempPath, self::FILE_OPEN_MODE_WRITE);
        if (! $fp) {
            throw new ManifestException("Failed to open temporary file [{$tempPath}] for writing.");
        }

        try {
            fwrite($fp, $contents);
            fflush($fp);
        } finally {
            fclose($fp);
        }

        try {
            if (@rename($tempPath, $path)) {
                return;
            }

            // Windows-safe fallback: if rename failed on Windows and target exists, try unlink + rename
            if (PHP_OS_FAMILY === 'Windows' && $this->exists($path)) {
                if (@unlink($path) && @rename($tempPath, $path)) {
                    return;
                }
            }

            throw new ManifestException("Failed to atomically rename temporary file [{$tempPath}] to [{$path}].");
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Resolve the lock file path for a given manifest file in the dedicated locks directory.
     */
    public function lockPath(string $path): string
    {
        $this->files->ensureDirectoryExists($this->locksDirectory);
        $real = realpath($path) ?: $path;
        $hash = hash('sha256', $real);

        return $this->locksDirectory.DIRECTORY_SEPARATOR.$hash.self::LOCK_FILE_SUFFIX;
    }
}
