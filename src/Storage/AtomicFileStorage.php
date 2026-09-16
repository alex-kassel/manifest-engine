<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Storage;

use AlexKassel\ManifestEngine\Contracts\StorageDriver;
use AlexKassel\ManifestEngine\Exceptions\ManifestException;
use AlexKassel\ManifestEngine\Exceptions\ManifestLockTimeoutException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Sleep;

class AtomicFileStorage implements StorageDriver
{
    public const DEFAULT_LOCKS_DIR_SUBFOLDER = 'manifest-locks';

    public const DEFAULT_LOCK_TIMEOUT_SECONDS = 10;

    public const DEFAULT_LOCK_TTL_SECONDS = 30;

    public const DEFAULT_SLEEP_MICROSECONDS = 10000;

    public const DEFAULT_EMPTY_CONTENT = '';

    public const LOCK_FILE_SUFFIX = '.lock';

    public const FILE_OPEN_MODE_LOCK = 'c+';

    public const LOCK_KEY_PREFIX = 'manifest:';

    public function __construct(
        protected readonly Filesystem $files = new Filesystem,
        protected ?string $locksDirectory = null,
        protected ?LockProvider $lockProvider = null,
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

        return (string) $this->files->sharedGet($path);
    }

    /**
     * Execute an atomic mutation under an exclusive lock.
     *
     * @param  callable(string): string  $callback
     *
     * @throws ManifestException
     * @throws ManifestLockTimeoutException
     */
    public function mutateLocked(string $path, callable $callback): string
    {
        $this->files->ensureDirectoryExists(dirname($path));

        if ($this->lockProvider !== null) {
            return $this->mutateWithLockProvider($path, $callback);
        }

        return $this->mutateWithLocalFileLock($path, $callback);
    }

    /**
     * Mutate manifest using Laravel Cache Atomic LockProvider.
     *
     * @param  callable(string): string  $callback
     *
     * @throws ManifestException
     * @throws ManifestLockTimeoutException
     */
    protected function mutateWithLockProvider(string $path, callable $callback): string
    {
        $canonicalPath = $this->canonicalizePath($path);
        $lockKey = self::LOCK_KEY_PREFIX.hash('sha256', $canonicalPath);
        $lock = $this->lockProvider->lock($lockKey, self::DEFAULT_LOCK_TTL_SECONDS);

        $newContent = self::DEFAULT_EMPTY_CONTENT;

        try {
            $lock->block(self::DEFAULT_LOCK_TIMEOUT_SECONDS, function () use ($path, $callback, &$newContent): void {
                $current = $this->exists($path) ? (string) $this->files->sharedGet($path) : self::DEFAULT_EMPTY_CONTENT;
                $newContent = $callback($current);
                $this->writeAtomic($path, $newContent);
            });
        } catch (LockTimeoutException) {
            throw new ManifestLockTimeoutException($path, self::DEFAULT_LOCK_TIMEOUT_SECONDS);
        }

        return $newContent;
    }

    /**
     * Mutate manifest using a non-blocking local file lock with timeout.
     *
     * @param  callable(string): string  $callback
     *
     * @throws ManifestException
     * @throws ManifestLockTimeoutException
     */
    protected function mutateWithLocalFileLock(string $path, callable $callback): string
    {
        $lockPath = $this->lockPath($path);
        $this->files->ensureDirectoryExists(dirname($lockPath));

        $lockFp = fopen($lockPath, self::FILE_OPEN_MODE_LOCK);
        if (! $lockFp) {
            throw new ManifestException("Failed to open lock file [{$lockPath}] for mutation.");
        }

        $timeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS;
        $start = microtime(true);
        $acquired = false;

        try {
            while (! $acquired) {
                if (flock($lockFp, LOCK_EX | LOCK_NB)) {
                    $acquired = true;
                    break;
                }

                if ((microtime(true) - $start) >= $timeoutSeconds) {
                    throw new ManifestLockTimeoutException($path, $timeoutSeconds);
                }

                if (class_exists(Sleep::class)) {
                    Sleep::usleep(self::DEFAULT_SLEEP_MICROSECONDS);
                } else {
                    usleep(self::DEFAULT_SLEEP_MICROSECONDS);
                }
            }

            $current = $this->exists($path) ? (string) $this->files->sharedGet($path) : self::DEFAULT_EMPTY_CONTENT;
            $newContent = $callback($current);

            $this->writeAtomic($path, $newContent);

            return $newContent;
        } finally {
            if ($acquired) {
                flock($lockFp, LOCK_UN);
            }
            fclose($lockFp);
        }
    }

    /**
     * Atomically write contents to a file using Laravel's native Filesystem::replace.
     */
    public function writeAtomic(string $path, string $contents): void
    {
        $directory = dirname($path);
        $this->files->ensureDirectoryExists($directory);

        $this->files->replace($path, $contents);
    }

    /**
     * Resolve the lock file path for a given manifest file in the dedicated locks directory.
     */
    public function lockPath(string $path): string
    {
        $this->files->ensureDirectoryExists($this->locksDirectory);
        $canonical = $this->canonicalizePath($path);
        $hash = hash('sha256', $canonical);

        return $this->locksDirectory.DIRECTORY_SEPARATOR.$hash.self::LOCK_FILE_SUFFIX;
    }

    /**
     * Normalize and canonicalize file path based on its parent directory.
     */
    public function canonicalizePath(string $path): string
    {
        $dirname = dirname($path);
        $realDir = realpath($dirname) ?: $dirname;

        return rtrim($realDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($path);
    }
}
