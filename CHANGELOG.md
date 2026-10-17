# Changelog

## 0.1.1 - 2026-10-17

- Fixed: the PHP version is now described as the oldest version the app accepts (from composer.json or the lock's platform setting), not as the version the server runs. 0.1.0 worded a declared `^8.2` as if the app ran on PHP 8.2.

## 0.1.0 - 2026-10-17

- First release: PHP and Laravel end-of-life status from composer.lock or composer.json, 90-day deadline warnings, abandoned and outdated packages from Packagist, upgrade size, score.
- `php artisan upgrade:audit` and `vendor/bin/laravel-upgrade-audit`, with `--json`, `--offline` and `--fail-under`.
- Support calendar checked on 2026-10-17.
