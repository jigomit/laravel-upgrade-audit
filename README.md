# Laravel Upgrade Audit

Point it at a Laravel app and it tells you, in one screen, how far the app is from current and what is forcing the date:

- **PHP and Laravel end-of-life status**, read from `composer.lock` (or `composer.json` when there is no lock), against the official support calendars.
- **Deadlines inside 90 days**, so "supported today" does not hide "unpatched in ten weeks".
- **Abandoned packages**, straight from Packagist, with the maintainer's suggested replacement when there is one.
- **Packages a major release behind** the latest stable version.
- **How big the upgrade is**: nothing to do, one pass, or a staged upgrade one major at a time.

It reads two files and calls the public Packagist API. It never runs your code, never sends your `composer.json` anywhere else, and needs no account.

## Install

```bash
composer require --dev jigomit/laravel-upgrade-audit
```

Requires PHP 8.2 or newer. No other runtime dependencies.

## Use it

Inside a Laravel app:

```bash
php artisan upgrade:audit
```

Anywhere else, or against another project folder:

```bash
vendor/bin/laravel-upgrade-audit /path/to/project
```

Options for both:

| Option | What it does |
|---|---|
| `--json` | Print the full report as JSON for scripts and dashboards |
| `--offline` | Skip the Packagist lookups; check PHP and Laravel only |
| `--fail-under=N` | Exit with code 1 when the score is below N, to fail a CI build |

## Example

Run against an app still on Laravel 9 and PHP 8.0:

```text
Laravel upgrade audit
=====================

Score: 13/100    Upgrade: Staged upgrade, one major at a time

Laravel  9        END OF LIFE
PHP      8.0      END OF LIFE
Packages 4        1 abandoned, 4 a major behind

Findings
--------
- Laravel 9 is 4 majors behind Laravel 13; its security fixes ended on 6 Feb 2024.
- PHP 8.0 stopped receiving security fixes on 26 Nov 2023.
- One package has been abandoned by its maintainer: fruitcake/laravel-cors.
- 4 packages are at least one major release behind the latest stable version.
- Laravel 9 is 4 majors behind Laravel 13. Move one major at a time with the test suite green between each.

Packages a major release behind
-------------------------------
- laravel/framework 9.52.16 -> 13.37.0
- fruitcake/laravel-cors 2.2.0 -> 3.0.0
- guzzlehttp/guzzle 7.8.1 -> 8.2.0
- spatie/laravel-permission 5.11.1 -> 8.3.0

Support dates checked on 2026-10-17 against php.net and laravel.com.
```

## In CI

Fail the build once the app slips too far behind:

```yaml
- run: composer install --no-interaction
- run: vendor/bin/laravel-upgrade-audit --fail-under=60
```

## The score

Starts at 100. Each major Laravel is behind costs 12 (capped at 50); an end-of-life Laravel costs 10 more, security-only 4. An end-of-life PHP costs 20, security-only 8. Each abandoned package costs 5 (capped at 20) and each package a major behind costs 1 (capped at 10). It is a rough sort order for a portfolio of apps, not a grade.

## Where the dates come from

`src/Calendar.php` holds the PHP table from [php.net/supported-versions](https://www.php.net/supported-versions.php) and the Laravel table from the [Laravel support policy](https://laravel.com/docs/releases). `Calendar::CHECKED_ON` says when they were last checked. When a release ships before this package catches up, pass your own tables:

```php
use Jigomit\UpgradeAudit\Auditor;
use Jigomit\UpgradeAudit\Calendar;

$auditor = new Auditor(new Calendar(laravel: [...Calendar::LARAVEL, '14' => [/* dates */]], currentLaravel: 14));
```

Pull requests that update the calendar are the most useful contribution there is.

## Use the engine directly

```php
use Jigomit\UpgradeAudit\Project;

$report = Project::load(base_path())->audit();

$report['laravel']['status'];   // current | security_only | eol | unknown
$report['deadlines'];           // list of warnings for dates inside 90 days
$report['packages']['abandoned'];
```

## Testing

```bash
composer test
```

## About

Built and maintained by [JIGOMIT](https://jigomit.com), who do fixed-price Laravel upgrades and care plans. It grew out of the check behind the [free Laravel audit](https://jigomit.com/laravel-audit) on our site, released so you can run it yourself.

## License

MIT. See [LICENSE](LICENSE).
