<?php

use Jigomit\UpgradeAudit\Auditor;
use Jigomit\UpgradeAudit\Packagist;
use Jigomit\UpgradeAudit\Project;
use Jigomit\UpgradeAudit\TextReport;
use Jigomit\UpgradeAudit\Tests\FakePackagist;

$fixture = fn (string $name): string => __DIR__.'/../Fixtures/'.$name;
$today = new DateTimeImmutable('2026-10-17');

it('reads constraints the way composer resolves them', function (string $constraint, ?string $minor) {
    expect((new Auditor)->minorFromConstraint($constraint))->toBe($minor);
})->with([
    ['^8.1', '8.1'], ['>=7.4', '7.4'], ['~9.0', '9.0'], ['8.*', '8.0'], ['7.4|8.0', '7.4'], ['^8.2 || ^8.3', '8.2'], ['', null],
]);

it('flags an old Laravel 9 app on PHP 8.0 as end of life with an abandoned package', function () use ($fixture, $today) {
    $project = Project::load($fixture('laravel9'));
    $auditor = new Auditor;
    $info = FakePackagist::standard()->lookupMany($auditor->packageNames($project->composerJson, $project->composerLock));
    $report = $auditor->analyse($project->composerJson, $project->composerLock, $info, $today);

    expect($report['laravel'])->toMatchArray(['major' => 9, 'status' => Auditor::STATUS_EOL, 'majors_behind' => 4, 'locked_version' => '9.52.16'])
        ->and($report['php'])->toMatchArray(['minor' => '8.0', 'status' => Auditor::STATUS_EOL])
        ->and($report['packages']['abandoned'])->toBe([['name' => 'fruitcake/laravel-cors', 'replacement' => null]])
        ->and(array_column($report['packages']['outdated'], 'name'))->toBe(['laravel/framework', 'fruitcake/laravel-cors', 'spatie/laravel-permission'])
        ->and($report['upgrade']['size'])->toBe(Auditor::SIZE_STAGED)
        ->and($report['score'])->toBeLessThan(30);
});

it('reports a current Laravel 13 app on PHP 8.4 as nothing to upgrade', function () use ($fixture, $today) {
    $project = Project::load($fixture('laravel13'));
    $report = (new Auditor)->analyse($project->composerJson, $project->composerLock, FakePackagist::standard()->lookupMany(['laravel/framework']), $today);

    expect($report['laravel']['status'])->toBe(Auditor::STATUS_CURRENT)
        ->and($report['php']['status'])->toBe(Auditor::STATUS_CURRENT)
        ->and($report['upgrade']['size'])->toBe(Auditor::SIZE_NONE)
        ->and($report['score'])->toBe(100);
});

it('treats PHP 8.2 as security-only before 31 December 2026 and end of life after it', function () {
    $json = ['require' => ['php' => '^8.2', 'laravel/framework' => '^12.0']];

    expect((new Auditor)->analyse($json, null, [], new DateTimeImmutable('2026-10-17'))['php']['status'])->toBe(Auditor::STATUS_SECURITY_ONLY)
        ->and((new Auditor)->analyse($json, null, [], new DateTimeImmutable('2027-01-02'))['php']['status'])->toBe(Auditor::STATUS_EOL);
});

it('falls back to composer.json constraints when there is no lock and marks versions older than the calendar as end of life', function () use ($fixture, $today) {
    $project = Project::load($fixture('no-lock'));
    $report = (new Auditor)->analyse($project->composerJson, null, [], $today);

    expect($report['laravel']['major'])->toBe(8)
        ->and($report['php']['minor'])->toBe('7.4')
        ->and($report['php']['status'])->toBe(Auditor::STATUS_EOL)
        ->and($report['packages']['checked'])->toBeFalse();

    expect((new Auditor)->analyse(['require' => ['php' => '^7.1']], null, [], $today)['php']['status'])->toBe(Auditor::STATUS_EOL);
});

it('skips platform requirements and never calls Packagist offline', function () use ($fixture) {
    $fake = FakePackagist::standard();
    $report = Project::load($fixture('laravel9'))->audit(online: false, packagist: $fake);

    expect($fake->requested)->toBe([])
        ->and(Packagist::isLookupable('ext-json'))->toBeFalse()
        ->and(Packagist::isLookupable('php'))->toBeFalse()
        ->and(Packagist::isLookupable('laravel/framework'))->toBeTrue()
        ->and($report['lock_read'])->toBeTrue();
});

it('takes the latest stable release, skipping pre-releases, and reads abandonment', function () {
    $info = Packagist::parse('acme/pkg', json_encode(['packages' => ['acme/pkg' => [
        ['version' => 'v3.0.0-RC1', 'abandoned' => 'acme/new-pkg'], ['version' => 'v2.4.1'],
    ]]]));

    expect($info)->toBe(['found' => true, 'latest' => '2.4.1', 'abandoned' => 'acme/new-pkg'])
        ->and(Packagist::parse('acme/pkg', null))->toBe(Packagist::unknown());
});

it('renders a readable text report', function () use ($fixture) {
    $text = TextReport::render(Project::load($fixture('laravel9'))->audit(packagist: FakePackagist::standard()));

    expect($text)->toContain('Laravel upgrade audit')
        ->toContain('END OF LIFE')
        ->toContain('fruitcake/laravel-cors')
        ->toContain('Staged upgrade, one major at a time');
});

it('explains a missing composer.json', function () {
    Project::load(sys_get_temp_dir().'/does-not-exist-'.uniqid());
})->throws(RuntimeException::class, 'No composer.json found');

it('warns about a security deadline inside 90 days', function () {
    $json = ['require' => ['php' => '^8.2', 'laravel/framework' => '^12.0']];

    $october = (new Auditor)->analyse($json, null, [], new DateTimeImmutable('2026-10-17'));
    $june = (new Auditor)->analyse($json, null, [], new DateTimeImmutable('2026-06-01'));

    expect($october['deadlines'])->toBe(['Deadline: PHP 8.2, the oldest version the app accepts, stops receiving security fixes on 31 Dec 2026, in 75 days.'])
        ->and($october['findings'][0])->toStartWith('Deadline: PHP 8.2')
        ->and($june['deadlines'])->toBe([]);
});

it('does not claim packages are clean on an offline run', function () use ($fixture, $today) {
    $project = Project::load($fixture('laravel13'));
    $report = (new Auditor)->analyse($project->composerJson, $project->composerLock, [], $today);

    expect($report['upgrade']['reason'])->toContain('Run without --offline');
});

it('turns a near deadline into an upgrade pass rather than nothing to do', function () {
    $report = (new Auditor)->analyse(['require' => ['php' => '^8.2', 'laravel/framework' => '^12.0']], null, [], new DateTimeImmutable('2026-10-17'));

    expect($report['upgrade']['size'])->toBe(Auditor::SIZE_SINGLE)
        ->and($report['upgrade']['reason'])->toContain('security deadline');
});


it('describes the PHP version as the declared minimum, not the server runtime', function () use ($today) {
    $report = (new Auditor)->analyse(['require' => ['php' => '^8.0', 'laravel/framework' => '^10.0']], null, [], $today);

    expect($report['php']['label'])->toStartWith('The app still accepts PHP 8.0')
        ->and($report['php']['label'])->toContain('check the server runs a supported version');
});
