<?php

declare(strict_types=1);

namespace App\Service\Partners;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Single-host storage. Keep this directory on a persistent local volume. */
final class PartnerFileStorage
{
    public function __construct(
        #[Autowire('%env(default:app.partner_storage_default:PARTNER_STORAGE_DIR)%')]
        private readonly string $directory,
    ) {
    }

    public function read(string $name, ?array $default = []): ?array
    {
        $file = $this->directory.'/'.$name;
        if (!is_file($file)) {
            return $default;
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Partner storage is unavailable.');
        }
        $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Partner storage is invalid.');
        }

        return $data;
    }

    /** Caller must hold the corresponding lock. Rename preserves the previous file on failure. */
    public function write(string $name, array $data): void
    {
        $this->ensureDirectory();
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = tempnam($this->directory, '.partner-');
        if ($temporary === false) {
            throw new RuntimeException('Partner storage is unavailable.');
        }
        try {
            if (file_put_contents($temporary, $encoded) !== strlen($encoded) || !rename($temporary, $this->directory.'/'.$name)) {
                throw new RuntimeException('Partner storage write failed.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return resource */
    public function lock(string $name)
    {
        $this->ensureDirectory();
        $handle = fopen($this->directory.'/'.$name.'.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Partner storage lock is unavailable.');
        }
        // Do not occupy PHP workers indefinitely during a stalled writer.
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Partner storage is busy.');
        }

        return $handle;
    }

    /** @param resource $handle */
    public function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Partner storage directory is unavailable.');
        }
    }
}
