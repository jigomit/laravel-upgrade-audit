<?php

namespace Jigomit\UpgradeAudit\Laravel;

use Illuminate\Console\Command;
use Jigomit\UpgradeAudit\Project;
use Jigomit\UpgradeAudit\TextReport;
use Throwable;

class AuditCommand extends Command
{
    protected $signature = 'upgrade:audit
        {path? : Project folder or composer.json (default: this app)}
        {--json : Print the report as JSON}
        {--offline : Skip the Packagist lookups}
        {--fail-under= : Exit with code 1 when the score is below this number}';

    protected $description = 'Check this app against the PHP and Laravel support calendar and Packagist';

    public function handle(): int
    {
        try {
            $report = Project::load((string) ($this->argument('path') ?: base_path()))->audit(! $this->option('offline'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return 2;
        }

        $text = $this->option('json')
            ? (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : rtrim(TextReport::render($report));

        foreach (explode(PHP_EOL, $text) as $line) {
            $this->line($line);
        }

        $threshold = $this->option('fail-under');

        return $threshold !== null && $report['score'] < (int) $threshold ? self::FAILURE : self::SUCCESS;
    }
}
