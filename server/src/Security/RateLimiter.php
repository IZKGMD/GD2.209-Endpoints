<?php

declare(strict_types=1);

namespace MuchoCore\Security;

final readonly class RateLimiter implements RateLimitBackend
{
    private const AUTO_CLEANUP_INTERVAL = 128;
    private const DEFAULT_STALE_AFTER = 3600;
    private const DEFAULT_MAX_ENTRIES = 64;
    private const MAX_CLEANUP_SCAN = 512;

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory !== null && trim($directory) !== ''
            ? $directory
            : dirname(__DIR__, 2) . '/storage/control/rate-limit';
    }

    public function allowStrict(
        string $key,
        int $limit,
        int $windowSeconds
    ): bool {
        if ($limit < 1 || $windowSeconds < 1) {
            return false;
        }

        $this->maybeCleanup();

        if (
            !is_dir($this->directory) &&
            !@mkdir($this->directory, 0700, true) &&
            !is_dir($this->directory)
        ) {
            return false;
        }

        $file = $this->directory . '/' . hash('sha256', $key) . '.json';
        $fp = @fopen($file, 'c+');

        if ($fp === false) {
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return false;
            }

            rewind($fp);
            $raw = stream_get_contents($fp);

            $state = is_string($raw) && $raw !== ''
                ? json_decode($raw, true)
                : null;

            $now = time();
            $start = is_array($state) ? (int)($state['start'] ?? 0) : 0;
            $count = is_array($state) ? (int)($state['count'] ?? 0) : 0;

            if ($start <= 0 || ($now - $start) >= $windowSeconds) {
                $start = $now;
                $count = 0;
            }

            $count++;

            rewind($fp);
            if (!ftruncate($fp, 0)) {
                return false;
            }

            $payload = json_encode([
                'start' => $start,
                'count' => $count,
            ], JSON_THROW_ON_ERROR);

            $written = fwrite($fp, $payload);
            if ($written !== strlen($payload)) {
                return false;
            }

            if (!fflush($fp)) {
                return false;
            }

            return $count <= $limit;
        } catch (Throwable) {
            return false;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Remove stale bucket files left behind by expired rate-limit windows.
     *
     * Only files older than the supplied age are eligible, so an active
     * bucket that is still being written remains untouched. Cleanup is
     * bounded to avoid turning a request into an unbounded directory scan.
     */
    public function cleanup(
        int $staleAfterSeconds = self::DEFAULT_STALE_AFTER,
        int $maxEntries = self::DEFAULT_MAX_ENTRIES
    ): int {
        if ($staleAfterSeconds < 1 || $maxEntries < 1 || !is_dir($this->directory)) {
            return 0;
        }

        $removed = 0;
        $cutoff = time() - $staleAfterSeconds;
        $scanned = 0;

        try {
            $iterator = new \FilesystemIterator(
                $this->directory,
                \FilesystemIterator::SKIP_DOTS
            );

            foreach ($iterator as $file) {
                if (++$scanned > self::MAX_CLEANUP_SCAN) {
                    break;
                }

                if (
                    !$file->isFile() ||
                    $file->getExtension() !== 'json'
                ) {
                    continue;
                }

                $mtime = $file->getMTime();

                if ($mtime >= $cutoff) {
                    continue;
                }

                if (@unlink($file->getPathname())) {
                    $removed++;

                    if ($removed >= $maxEntries) {
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            return $removed;
        }

        return $removed;
    }

    private function maybeCleanup(): void
    {
        static $calls = 0;

        $calls++;

        if ($calls >= self::AUTO_CLEANUP_INTERVAL) {
            $calls = 0;
            $this->cleanup();
        }
    }

    public function allow(
        string $key,
        int $limit,
        int $windowSeconds
    ): bool {
        if ($limit < 1 || $windowSeconds < 1) {
            return true;
        }

        $this->maybeCleanup();

        if (
            !is_dir($this->directory) &&
            !@mkdir($this->directory, 0700, true) &&
            !is_dir($this->directory)
        ) {
            return true; // Keep the game protocol available if storage is unavailable.
        }

        $file = $this->directory . '/' . hash('sha256', $key) . '.json';
        $fp = @fopen($file, 'c+');

        if ($fp === false) {
            return true;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return true;
            }

            rewind($fp);
            $raw = stream_get_contents($fp);

            $state = is_string($raw) && $raw !== ''
                ? json_decode($raw, true)
                : null;

            $now = time();
            $start = is_array($state) ? (int)($state['start'] ?? 0) : 0;
            $count = is_array($state) ? (int)($state['count'] ?? 0) : 0;

            if ($start <= 0 || ($now - $start) >= $windowSeconds) {
                $start = $now;
                $count = 0;
            }

            $count++;

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode([
                'start' => $start,
                'count' => $count,
            ], JSON_THROW_ON_ERROR));
            fflush($fp);

            return $count <= $limit;
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
