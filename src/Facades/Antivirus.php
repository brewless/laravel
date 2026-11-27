<?php

declare(strict_types=1);

namespace Brewless\Laravel\Facades;

use Brewless\Laravel\Malware\AntivirusApi;
use Brewless\Laravel\Malware\FakeAntivirus;
use Brewless\Laravel\Malware\Scanner;
use Brewless\Laravel\Malware\ScanResult;
use Illuminate\Support\Facades\Facade;

/**
 * Brewless Malware Protection for the application's disks
 * (Brewless\Laravel\Malware\Antivirus). Only a door to it: everything it does
 * is in that class.
 *
 * @method static Scanner disk(?string $name = null)
 * @method static ScanResult status(string $path)
 * @method static void requestScan(string $path)
 * @method static ScanResult wait(string $path, int $timeoutSeconds = 60)
 * @method static bool release(string $path)
 * @method static string stagingPath(string $path)
 *
 * @see \Brewless\Laravel\Malware\Antivirus
 */
final class Antivirus extends Facade
{
    /**
     * Swap Malware Protection for the in-memory fake in the application's own tests: nothing is read or sent,
     * every file is pending until the test marks it, and the fake has assertions. Code that type-hints
     * AntivirusApi gets the fake too.
     */
    public static function fake(): FakeAntivirus
    {
        self::swap($fake = new FakeAntivirus);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return AntivirusApi::class;
    }
}
