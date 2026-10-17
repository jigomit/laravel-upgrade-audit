<?php

namespace Jigomit\UpgradeAudit;

/**
 * Renders an audit report as plain text for a terminal or a CI log.
 */
final class TextReport
{
    /**
     * @param  array<string, mixed>  $report
     */
    public static function render(array $report): string
    {
        $sizes = [
            Auditor::SIZE_NONE => 'Nothing to upgrade',
            Auditor::SIZE_SINGLE => 'One upgrade pass',
            Auditor::SIZE_STAGED => 'Staged upgrade, one major at a time',
        ];

        $lines = [
            'Laravel upgrade audit',
            str_repeat('=', 21),
            '',
            sprintf('Score: %d/100    Upgrade: %s', $report['score'], $sizes[$report['upgrade']['size']] ?? $report['upgrade']['size']),
            '',
            sprintf('Laravel  %-8s %s', $report['laravel']['major'] ?? '?', self::status($report['laravel']['status'])),
            sprintf('PHP min  %-8s %s', $report['php']['minor'] ?? '?', self::status($report['php']['status'])),
            sprintf('Packages %-8s %s', $report['packages']['total'], $report['packages']['checked']
                ? count($report['packages']['abandoned']).' abandoned, '.count($report['packages']['outdated']).' a major behind'
                : 'not checked (offline)'),
            '',
            'Findings',
            '--------',
        ];

        foreach ($report['findings'] as $finding) {
            $lines[] = '- '.$finding;
        }

        if ($report['packages']['outdated'] !== []) {
            $lines[] = '';
            $lines[] = 'Packages a major release behind';
            $lines[] = '-------------------------------';
            foreach ($report['packages']['outdated'] as $p) {
                $lines[] = sprintf('- %s %s -> %s', $p['name'], $p['locked'], $p['latest']);
            }
        }

        if (empty($report['lock_read'])) {
            $lines[] = '';
            $lines[] = 'Note: no composer.lock was found, so versions come from composer.json constraints.';
        }

        $lines[] = '';
        $lines[] = 'PHP min is the oldest version composer.json accepts. Check the server runtime separately (php -v).';
        $lines[] = 'Support dates checked on '.$report['calendar_checked_on'].' against php.net and laravel.com.';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private static function status(string $status): string
    {
        return match ($status) {
            Auditor::STATUS_CURRENT => 'supported',
            Auditor::STATUS_SECURITY_ONLY => 'security fixes only',
            Auditor::STATUS_EOL => 'END OF LIFE',
            default => 'unknown',
        };
    }
}
