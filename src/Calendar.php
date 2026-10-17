<?php

namespace Jigomit\UpgradeAudit;

/**
 * The PHP and Laravel support calendar the audit measures against.
 *
 * Sources: https://www.php.net/supported-versions.php and
 * https://laravel.com/docs/releases (support policy table).
 * Checked on Calendar::CHECKED_ON. Pass your own tables to the constructor to
 * override them before a release of this package catches up.
 */
final class Calendar
{
    public const CHECKED_ON = '2026-10-17';

    /**
     * @param  array<string, array{released: string, active_until: string, security_until: string}>  $php
     * @param  array<string, array{released: string, bug_fixes_until: string, security_until: string, php: string}>  $laravel
     */
    public function __construct(
        public readonly array $php = self::PHP,
        public readonly array $laravel = self::LARAVEL,
        public readonly string $currentPhp = '8.5',
        public readonly int $currentLaravel = 13,
    ) {}

    public const PHP = [
        '7.4' => ['released' => '2019-11-28', 'active_until' => '2021-11-28', 'security_until' => '2022-11-28'],
        '8.0' => ['released' => '2020-11-26', 'active_until' => '2022-11-26', 'security_until' => '2023-11-26'],
        '8.1' => ['released' => '2021-11-25', 'active_until' => '2023-11-25', 'security_until' => '2025-12-31'],
        '8.2' => ['released' => '2022-12-08', 'active_until' => '2024-12-31', 'security_until' => '2026-12-31'],
        '8.3' => ['released' => '2023-11-23', 'active_until' => '2025-12-31', 'security_until' => '2027-12-31'],
        '8.4' => ['released' => '2024-11-21', 'active_until' => '2026-12-31', 'security_until' => '2028-12-31'],
        '8.5' => ['released' => '2025-11-20', 'active_until' => '2027-12-31', 'security_until' => '2029-12-31'],
    ];

    public const LARAVEL = [
        '5' => ['released' => '2015-02-04', 'bug_fixes_until' => '2020-03-03', 'security_until' => '2020-09-03', 'php' => '7.2+'],
        '6' => ['released' => '2019-09-03', 'bug_fixes_until' => '2022-01-25', 'security_until' => '2022-09-06', 'php' => '7.2–8.0'],
        '7' => ['released' => '2020-03-03', 'bug_fixes_until' => '2020-10-06', 'security_until' => '2021-03-03', 'php' => '7.2–8.0'],
        '8' => ['released' => '2020-09-08', 'bug_fixes_until' => '2022-07-26', 'security_until' => '2023-01-24', 'php' => '7.3–8.1'],
        '9' => ['released' => '2022-02-08', 'bug_fixes_until' => '2023-08-08', 'security_until' => '2024-02-06', 'php' => '8.0–8.2'],
        '10' => ['released' => '2023-02-14', 'bug_fixes_until' => '2024-08-06', 'security_until' => '2025-02-04', 'php' => '8.1–8.3'],
        '11' => ['released' => '2024-03-12', 'bug_fixes_until' => '2025-09-03', 'security_until' => '2026-03-12', 'php' => '8.2–8.4'],
        '12' => ['released' => '2025-02-24', 'bug_fixes_until' => '2026-08-13', 'security_until' => '2027-02-24', 'php' => '8.2–8.5'],
        '13' => ['released' => '2026-02-24', 'bug_fixes_until' => '2027-08-10', 'security_until' => '2028-02-24', 'php' => '8.3+'],
    ];
}
