<?php

namespace Jigomit\UpgradeAudit;

use DateTimeImmutable;

/**
 * Pure analysis: composer.json (and optionally composer.lock) in, a report array
 * out. No network: Packagist data is passed in, so the same code serves an
 * offline run, a CLI run and a web form.
 *
 * @phpstan-type PhpReport array{constraint: ?string, locked: ?string, minor: ?string, status: string, active_until: ?string, security_until: ?string, label: string}
 * @phpstan-type LaravelReport array{constraint: ?string, locked_version: ?string, major: ?int, status: string, bug_fixes_until: ?string, security_until: ?string, majors_behind: ?int, label: string}
 * @phpstan-type PackagesReport array{total: int, checked: bool, abandoned: list<array{name: string, replacement: ?string}>, outdated: list<array{name: string, locked: string, latest: string}>, unknown: int}
 * @phpstan-type Report array{php: PhpReport, laravel: LaravelReport, packages: PackagesReport, upgrade: array{size: string, reason: string}, score: int, deadlines: list<string>, findings: list<string>, calendar_checked_on: string, generated_at: string}
 */
class Auditor
{
    public const STATUS_CURRENT = 'current';

    public const STATUS_SECURITY_ONLY = 'security_only';

    public const STATUS_EOL = 'eol';

    public const STATUS_UNKNOWN = 'unknown';

    /** Up to date: nothing to upgrade, keep it current. */
    public const SIZE_NONE = 'none';

    /** One or two majors, a runtime bump, a few package swaps: one focused pass. */
    public const SIZE_SINGLE = 'single_pass';

    /** Three or more majors behind, or several abandoned packages: staged, one major at a time. */
    public const SIZE_STAGED = 'staged';

    /** Support dates closer than this are called out as a deadline. */
    public const DEADLINE_DAYS = 90;

    /** Cap so one giant lock cannot turn into hundreds of Packagist calls. */
    public const MAX_PACKAGES = 150;

    public function __construct(private readonly Calendar $calendar = new Calendar) {}

    /**
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     * @param  array<string, array{found: bool, latest: ?string, abandoned: bool|string}>  $packagist  keyed by package name; empty for an offline run
     * @return Report
     */
    public function analyse(array $composerJson, ?array $composerLock = null, array $packagist = [], ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');

        $php = $this->analysePhp($composerJson, $composerLock, $today);
        $laravel = $this->analyseLaravel($composerJson, $composerLock, $today);
        $packages = $this->analysePackages($composerJson, $composerLock, $packagist);
        $deadlines = $this->deadlines($php, $laravel, $today);
        $upgrade = $this->upgradeSize($php, $laravel, $packages, $deadlines);

        return [
            'php' => $php,
            'laravel' => $laravel,
            'packages' => $packages,
            'upgrade' => $upgrade,
            'score' => $this->score($php, $laravel, $packages),
            'deadlines' => $deadlines,
            'findings' => [...$deadlines, ...$this->findings($php, $laravel, $packages, $upgrade)],
            'calendar_checked_on' => Calendar::CHECKED_ON,
            'generated_at' => (new DateTimeImmutable)->format(DATE_ATOM),
        ];
    }

    /**
     * Package names worth asking Packagist about: everything installed (from the
     * lock), else the direct requirements.
     *
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     * @return list<string>
     */
    public function packageNames(array $composerJson, ?array $composerLock = null): array
    {
        $names = array_keys($this->lockPackages($composerLock));

        if ($names === []) {
            $names = array_keys($this->requirements($composerJson));
        }

        $names = array_values(array_unique(array_filter($names, fn (string $n): bool => Packagist::isLookupable($n))));

        return array_slice($names, 0, self::MAX_PACKAGES);
    }

    /**
     * The first X.Y a constraint pins to: ^8.1 → 8.1, >=7.4 → 7.4, ~9.0 → 9.0, 8.* → 8.0.
     * Multi-range constraints (7.4|8.0) resolve to the lowest, which is what the
     * app still promises to run on.
     */
    public function minorFromConstraint(?string $constraint): ?string
    {
        if ($constraint === null || trim($constraint) === '') {
            return null;
        }

        preg_match_all('/(?<![\d.])(\d+)(?:\.(\d+|\*))?/', $constraint, $matches, PREG_SET_ORDER);

        $candidates = [];
        foreach ($matches as $match) {
            $candidates[] = [(int) $match[1], isset($match[2]) && $match[2] !== '*' ? (int) $match[2] : 0];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return $candidates[0][0].'.'.$candidates[0][1];
    }

    public function majorFromVersion(?string $version): ?int
    {
        return $version !== null && preg_match('/(\d+)/', $version, $m) ? (int) $m[1] : null;
    }

    /**
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     * @return PhpReport
     */
    private function analysePhp(array $composerJson, ?array $composerLock, DateTimeImmutable $today): array
    {
        $constraint = $this->requirements($composerJson)['php'] ?? null;
        $locked = $this->lockedPhp($composerLock);
        $minor = $this->minorFromConstraint($locked ?? $constraint);
        $row = $minor !== null ? ($this->calendar->php[$minor] ?? null) : null;
        $status = $this->statusFor($row, 'active_until', 'security_until', $today, $minor, array_keys($this->calendar->php));

        return [
            'constraint' => $constraint,
            'locked' => $locked,
            'minor' => $minor,
            'status' => $status,
            'active_until' => $row['active_until'] ?? null,
            'security_until' => $row['security_until'] ?? null,
            'label' => $this->phpLabel($minor, $status, $row),
        ];
    }

    /**
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     * @return LaravelReport
     */
    private function analyseLaravel(array $composerJson, ?array $composerLock, DateTimeImmutable $today): array
    {
        $constraint = $this->requirements($composerJson)['laravel/framework'] ?? null;
        $lockedVersion = $this->lockPackages($composerLock)['laravel/framework'] ?? null;
        $major = $lockedVersion !== null ? $this->majorFromVersion($lockedVersion) : $this->majorFromVersion($this->minorFromConstraint($constraint));
        $row = $major !== null ? ($this->calendar->laravel[(string) $major] ?? null) : null;
        $current = $this->calendar->currentLaravel;
        $status = $this->statusFor($row, 'bug_fixes_until', 'security_until', $today, $major === null ? null : (string) $major, array_keys($this->calendar->laravel));

        return [
            'constraint' => $constraint,
            'locked_version' => $lockedVersion !== null ? ltrim($lockedVersion, 'vV') : null,
            'major' => $major,
            'status' => $status,
            'bug_fixes_until' => $row['bug_fixes_until'] ?? null,
            'security_until' => $row['security_until'] ?? null,
            'majors_behind' => $major === null ? null : max(0, $current - $major),
            'label' => $this->laravelLabel($major, $status, $row, $current),
        ];
    }

    /**
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     * @param  array<string, array{found: bool, latest: ?string, abandoned: bool|string}>  $packagist
     * @return PackagesReport
     */
    private function analysePackages(array $composerJson, ?array $composerLock, array $packagist): array
    {
        $lockVersions = $this->lockPackages($composerLock);
        $names = $this->packageNames($composerJson, $composerLock);
        $abandoned = [];
        $outdated = [];
        $unknown = 0;

        foreach ($names as $name) {
            $info = $packagist[$name] ?? null;

            if ($info === null || empty($info['found'])) {
                $unknown++;

                continue;
            }

            if ($info['abandoned'] !== false) {
                $abandoned[] = ['name' => $name, 'replacement' => is_string($info['abandoned']) ? $info['abandoned'] : null];
            }

            $locked = $lockVersions[$name] ?? null;
            $latest = $info['latest'] ?? null;
            $lockedMajor = $this->majorFromVersion($locked);
            $latestMajor = $this->majorFromVersion($latest);

            if ($lockedMajor !== null && $latestMajor !== null && $lockedMajor < $latestMajor) {
                $outdated[] = ['name' => $name, 'locked' => ltrim((string) $locked, 'vV'), 'latest' => (string) $latest];
            }
        }

        return [
            'total' => count($names),
            'checked' => $packagist !== [],
            'abandoned' => $abandoned,
            'outdated' => $outdated,
            'unknown' => $packagist === [] ? count($names) : $unknown,
        ];
    }

    /**
     * @param  PhpReport  $php
     * @param  LaravelReport  $laravel
     * @param  PackagesReport  $packages
     * @param  list<string>  $deadlines
     * @return array{size: string, reason: string}
     */
    private function upgradeSize(array $php, array $laravel, array $packages, array $deadlines = []): array
    {
        $behind = $laravel['majors_behind'];
        $abandoned = count($packages['abandoned']);
        $phpEol = $php['status'] === self::STATUS_EOL;

        if ($behind === null) {
            return ['size' => self::SIZE_SINGLE, 'reason' => 'laravel/framework was not found in the require block or the lock, so the size is a guess until the lock file is read.'];
        }

        if ($behind >= 3 || $abandoned >= 3) {
            return ['size' => self::SIZE_STAGED, 'reason' => $behind >= 3
                ? "Laravel {$laravel['major']} is {$behind} majors behind Laravel {$this->calendar->currentLaravel}. Move one major at a time with the test suite green between each."
                : "{$abandoned} abandoned packages need maintained replacements before the framework can move."];
        }

        if ($behind <= 1 && ! $phpEol && $laravel['status'] !== self::STATUS_EOL && $abandoned === 0 && $deadlines !== []) {
            return ['size' => self::SIZE_SINGLE, 'reason' => 'Supported today, but a security deadline falls inside '.self::DEADLINE_DAYS.' days. Plan one upgrade pass before it.'];
        }

        if ($behind <= 1 && ! $phpEol && $laravel['status'] !== self::STATUS_EOL && $abandoned === 0) {
            return ['size' => self::SIZE_NONE, 'reason' => $packages['checked']
                ? 'Laravel and PHP are inside their support windows and nothing the app depends on has been abandoned.'
                : 'Laravel and PHP are inside their support windows. Run without --offline to check the packages too.'];
        }

        $parts = [];
        if ($behind > 0) {
            $parts[] = "Laravel {$laravel['major']} is {$behind} ".($behind === 1 ? 'major' : 'majors')." behind Laravel {$this->calendar->currentLaravel}";
        }
        if ($phpEol) {
            $parts[] = "PHP {$php['minor']} no longer receives security fixes";
        }
        if ($abandoned > 0) {
            $parts[] = $abandoned.' '.($abandoned === 1 ? 'package is' : 'packages are').' abandoned';
        }

        return ['size' => self::SIZE_SINGLE, 'reason' => implode('; ', $parts).'. One focused upgrade pass brings it current.'];
    }

    /**
     * @param  PhpReport  $php
     * @param  LaravelReport  $laravel
     * @param  PackagesReport  $packages
     */
    private function score(array $php, array $laravel, array $packages): int
    {
        $score = 100;
        $score -= min(50, ($laravel['majors_behind'] ?? 3) * 12);
        $score -= match ($laravel['status']) {
            self::STATUS_EOL, self::STATUS_UNKNOWN => 10,
            self::STATUS_SECURITY_ONLY => 4,
            default => 0,
        };
        $score -= match ($php['status']) {
            self::STATUS_EOL => 20,
            self::STATUS_UNKNOWN => 10,
            self::STATUS_SECURITY_ONLY => 8,
            default => 0,
        };
        $score -= min(20, count($packages['abandoned']) * 5);
        $score -= min(10, count($packages['outdated']));

        return max(0, min(100, $score));
    }

    /**
     * @param  PhpReport  $php
     * @param  LaravelReport  $laravel
     * @param  PackagesReport  $packages
     * @param  array{size: string, reason: string}  $upgrade
     * @return list<string>
     */
    private function findings(array $php, array $laravel, array $packages, array $upgrade): array
    {
        $findings = [$laravel['label'], $php['label']];

        if ($packages['checked']) {
            $count = count($packages['abandoned']);
            $findings[] = match (true) {
                $count === 0 => "None of the {$packages['total']} packages checked has been abandoned.",
                $count === 1 => 'One package has been abandoned by its maintainer: '.$this->describe($packages['abandoned'][0]).'.',
                default => "{$count} packages have been abandoned by their maintainers: ".implode(', ', array_map($this->describe(...), $packages['abandoned'])).'.',
            };

            $outdated = count($packages['outdated']);
            if ($outdated > 0) {
                $findings[] = "{$outdated} ".($outdated === 1 ? 'package is' : 'packages are').' at least one major release behind the latest stable version.';
            }

            if ($packages['unknown'] > 0) {
                $findings[] = "{$packages['unknown']} ".($packages['unknown'] === 1 ? 'package was' : 'packages were').' not found on Packagist (private or renamed).';
            }
        } else {
            $findings[] = "{$packages['total']} packages not checked against Packagist (offline run).";
        }

        $findings[] = $upgrade['reason'];

        return $findings;
    }

    /**
     * Support dates that end within DEADLINE_DAYS, worded as a warning.
     *
     * @param  PhpReport  $php
     * @param  LaravelReport  $laravel
     * @return list<string>
     */
    private function deadlines(array $php, array $laravel, DateTimeImmutable $today): array
    {
        $out = [];
        $checks = [
            ["PHP {$php['minor']}", $php['security_until']],
            ["Laravel {$laravel['major']}", $laravel['security_until']],
        ];

        foreach ($checks as [$name, $date]) {
            if ($date === null) {
                continue;
            }

            $days = (int) $today->diff(new DateTimeImmutable($date))->format('%r%a');

            if ($days >= 0 && $days <= self::DEADLINE_DAYS) {
                $out[] = "Deadline: {$name} stops receiving security fixes on {$this->date($date)}, in {$days} days.";
            }
        }

        return $out;
    }

    /**
     * @param  array{name: string, replacement: ?string}  $package
     */
    private function describe(array $package): string
    {
        return $package['replacement'] ? "{$package['name']} (use {$package['replacement']})" : $package['name'];
    }

    /**
     * @param  array<string, string>|null  $row
     * @param  list<int|string>  $known
     */
    private function statusFor(?array $row, string $activeKey, string $securityKey, DateTimeImmutable $today, ?string $version, array $known): string
    {
        if ($row === null) {
            if ($version === null || $known === []) {
                return self::STATUS_UNKNOWN;
            }

            $oldest = (string) min(array_map('strval', $known));

            return version_compare($version, $oldest, '<') ? self::STATUS_EOL : self::STATUS_UNKNOWN;
        }

        if (! empty($row[$securityKey]) && new DateTimeImmutable($row[$securityKey]) < $today) {
            return self::STATUS_EOL;
        }

        if (! empty($row[$activeKey]) && new DateTimeImmutable($row[$activeKey]) < $today) {
            return self::STATUS_SECURITY_ONLY;
        }

        return self::STATUS_CURRENT;
    }

    /**
     * @param  array<string, string>|null  $row
     */
    private function phpLabel(?string $minor, string $status, ?array $row): string
    {
        if ($minor === null) {
            return 'No PHP version is declared, so the runtime could not be checked against its support dates.';
        }

        return match ($status) {
            self::STATUS_EOL => "PHP {$minor} stopped receiving security fixes".(! empty($row['security_until']) ? ' on '.$this->date($row['security_until']) : '').'.',
            self::STATUS_SECURITY_ONLY => "PHP {$minor} gets security fixes only, until ".$this->date($row['security_until'] ?? null).'.',
            self::STATUS_CURRENT => "PHP {$minor} is actively supported".(! empty($row['security_until']) ? ', with security fixes until '.$this->date($row['security_until']) : '').'.',
            default => "PHP {$minor} is not in the support calendar this version knows about.",
        };
    }

    /**
     * @param  array<string, string>|null  $row
     */
    private function laravelLabel(?int $major, string $status, ?array $row, int $current): string
    {
        if ($major === null) {
            return 'laravel/framework is not required, so the app could not be placed on the Laravel release calendar.';
        }

        $behind = max(0, $current - $major);
        $distance = $behind === 0 ? 'the current major' : "{$behind} ".($behind === 1 ? 'major' : 'majors')." behind Laravel {$current}";

        return match ($status) {
            self::STATUS_EOL => "Laravel {$major} is {$distance}; its security fixes ended".(! empty($row['security_until']) ? ' on '.$this->date($row['security_until']) : '').'.',
            self::STATUS_SECURITY_ONLY => "Laravel {$major} is {$distance}; bug fixes have ended and security fixes run until ".$this->date($row['security_until'] ?? null).'.',
            self::STATUS_CURRENT => "Laravel {$major} is {$distance}".(! empty($row['bug_fixes_until']) ? ', with bug fixes until '.$this->date($row['bug_fixes_until']) : '').'.',
            default => "Laravel {$major} is not in the release calendar this version knows about.",
        };
    }

    private function date(?string $iso): string
    {
        return $iso ? (new DateTimeImmutable($iso))->format('j M Y') : 'an unknown date';
    }

    /**
     * @param  array<string, mixed>  $composerJson
     * @return array<string, string>
     */
    private function requirements(array $composerJson): array
    {
        $out = [];
        foreach ((array) ($composerJson['require'] ?? []) as $name => $constraint) {
            if (is_string($name) && is_scalar($constraint)) {
                $out[strtolower($name)] = (string) $constraint;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $composerLock
     * @return array<string, string>
     */
    private function lockPackages(?array $composerLock): array
    {
        $out = [];
        foreach ((array) ($composerLock['packages'] ?? []) as $package) {
            if (is_array($package) && ! empty($package['name']) && is_string($package['name'])) {
                $out[strtolower($package['name'])] = (string) ($package['version'] ?? '');
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $composerLock
     */
    private function lockedPhp(?array $composerLock): ?string
    {
        foreach ([$composerLock['platform-overrides']['php'] ?? null, $composerLock['platform']['php'] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
