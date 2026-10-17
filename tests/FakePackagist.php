<?php

namespace Jigomit\UpgradeAudit\Tests;

use Jigomit\UpgradeAudit\Packagist;

/** Serves canned p2 responses instead of calling repo.packagist.org. */
class FakePackagist extends Packagist
{
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string, array<int, array<string, mixed>>> $packages */
    public function __construct(private readonly array $packages) {}

    protected function fetch(string $url): ?string
    {
        $this->requested[] = $url;
        $name = substr($url, strlen(self::ENDPOINT) + 1, -5);

        return isset($this->packages[$name]) ? json_encode(['packages' => [$name => $this->packages[$name]]]) : null;
    }

    public static function standard(): self
    {
        return new self([
            'laravel/framework' => [['version' => 'v13.4.0'], ['version' => 'v14.0.0-beta1']],
            'fruitcake/laravel-cors' => [['version' => 'v3.0.0', 'abandoned' => true]],
            'guzzlehttp/guzzle' => [['version' => '7.9.2']],
            'spatie/laravel-permission' => [['version' => '6.10.0']],
        ]);
    }
}
