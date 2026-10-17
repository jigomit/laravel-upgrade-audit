<?php

use Jigomit\UpgradeAudit\Laravel\UpgradeAuditServiceProvider;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->app->register(UpgradeAuditServiceProvider::class);
});

it('runs as an artisan command and prints the report', function () {
    $this->artisan('upgrade:audit', ['path' => __DIR__.'/../Fixtures/laravel9', '--offline' => true])
        ->expectsOutputToContain('Laravel upgrade audit')
        ->expectsOutputToContain('END OF LIFE')
        ->assertExitCode(0);
});

it('fails the build when the score is under the threshold', function () {
    $this->artisan('upgrade:audit', ['path' => __DIR__.'/../Fixtures/laravel9', '--offline' => true, '--fail-under' => 80])
        ->assertExitCode(1);
});

it('prints JSON for scripts', function () {
    $this->artisan('upgrade:audit', ['path' => __DIR__.'/../Fixtures/laravel13', '--offline' => true, '--json' => true])
        ->expectsOutputToContain('"size": "none"')
        ->assertExitCode(0);
});
