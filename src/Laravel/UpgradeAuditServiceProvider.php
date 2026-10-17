<?php

namespace Jigomit\UpgradeAudit\Laravel;

use Illuminate\Support\ServiceProvider;

class UpgradeAuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AuditCommand::class]);
        }
    }
}
