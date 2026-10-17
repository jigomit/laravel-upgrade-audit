<?php

namespace Jigomit\UpgradeAudit;

use RuntimeException;

/**
 * Reads composer.json and, when present, composer.lock from a project folder
 * (or a direct path to either file) and runs the audit.
 */
final class Project
{
    /**
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     */
    private function __construct(
        public readonly string $root,
        public readonly array $composerJson,
        public readonly ?array $composerLock,
    ) {}

    public static function load(string $path): self
    {
        $root = is_file($path) ? dirname($path) : rtrim($path, DIRECTORY_SEPARATOR);
        $jsonPath = $root.DIRECTORY_SEPARATOR.'composer.json';
        $lockPath = $root.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_file($jsonPath)) {
            throw new RuntimeException("No composer.json found in {$root}.");
        }

        return new self($root, self::decode($jsonPath), is_file($lockPath) ? self::decode($lockPath) : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(bool $online = true, ?Auditor $auditor = null, ?Packagist $packagist = null): array
    {
        $auditor ??= new Auditor;
        $names = $auditor->packageNames($this->composerJson, $this->composerLock);
        $info = $online ? ($packagist ?? new Packagist)->lookupMany($names) : [];

        return $auditor->analyse($this->composerJson, $this->composerLock, $info) + ['lock_read' => $this->composerLock !== null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            throw new RuntimeException("{$file} is not valid JSON.");
        }

        return $data;
    }
}
