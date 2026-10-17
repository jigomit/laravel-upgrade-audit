<?php

namespace Jigomit\UpgradeAudit;

/**
 * Reads Packagist's public p2 metadata for the latest stable release of a
 * package and whether its maintainer has abandoned it. Never throws: an
 * unreachable or unknown package comes back as `found: false`.
 *
 * @phpstan-type PackageInfo array{found: bool, latest: ?string, abandoned: bool|string}
 */
class Packagist
{
    public const ENDPOINT = 'https://repo.packagist.org/p2';

    /** @var array<string, PackageInfo> */
    private array $memo = [];

    public function __construct(private readonly int $timeoutSeconds = 5) {}

    /**
     * @param  list<string>  $names
     * @return array<string, PackageInfo>
     */
    public function lookupMany(array $names): array
    {
        $out = [];

        foreach ($names as $name) {
            $out[$name] = $this->lookup($name);
        }

        return $out;
    }

    /**
     * @return PackageInfo
     */
    public function lookup(string $name): array
    {
        $name = strtolower(trim($name));

        if (! self::isLookupable($name)) {
            return self::unknown();
        }

        return $this->memo[$name] ??= $this->parse($name, $this->fetch(self::ENDPOINT.'/'.$name.'.json'));
    }

    /** Platform requirements (php, ext-*, lib-*) and malformed names are never on Packagist. */
    public static function isLookupable(string $name): bool
    {
        if ($name === 'php' || str_starts_with($name, 'php-') || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || str_starts_with($name, 'composer-')) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/', $name);
    }

    /**
     * @return PackageInfo
     */
    public static function parse(string $name, ?string $json): array
    {
        if ($json === null) {
            return self::unknown();
        }

        $data = json_decode($json, true);
        $versions = is_array($data) ? ($data['packages'][$name] ?? null) : null;

        if (! is_array($versions) || $versions === []) {
            return self::unknown();
        }

        $abandoned = $versions[0]['abandoned'] ?? false;

        return [
            'found' => true,
            'latest' => self::latestStable($versions),
            'abandoned' => is_string($abandoned) && $abandoned !== '' ? $abandoned : (bool) $abandoned,
        ];
    }

    /**
     * @return PackageInfo
     */
    public static function unknown(): array
    {
        return ['found' => false, 'latest' => null, 'abandoned' => false];
    }

    /**
     * p2 lists releases newest first, pre-releases included; take the first tag
     * that is not an alpha, beta, RC or dev build.
     *
     * @param  list<array<string, mixed>>  $versions
     */
    private static function latestStable(array $versions): ?string
    {
        foreach ($versions as $release) {
            $version = (string) ($release['version'] ?? '');

            if ($version !== '' && ! preg_match('/(alpha|beta|rc|dev)/i', $version)) {
                return ltrim($version, 'vV');
            }
        }

        $fallback = (string) ($versions[0]['version'] ?? '');

        return $fallback === '' ? null : ltrim($fallback, 'vV');
    }

    protected function fetch(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: jigomit/laravel-upgrade-audit\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status === 200 ? $body : null;
    }
}
