<?php

namespace Admnio\Sunset\Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\TestCase as BaseDuskTestCase;

abstract class DuskTestCase extends BaseDuskTestCase
{
    public static function prepare(): void
    {
        // Don't auto-start ChromeDriver. CI starts it; locally the developer
        // starts it via `chromedriver --port=9515` before running Dusk.
    }

    /**
     * Skip browser tests up-front if ChromeDriver isn't reachable on port 9515.
     *
     * Dusk's base TestCase extends Illuminate\Foundation\Testing\TestCase,
     * which loads a Laravel application from bootstrap/app.php — a file that
     * doesn't exist in a package context. We detect ChromeDriver availability
     * first and skip cleanly before Foundation setUp can crash.
     *
     * When Chrome IS available, CI (or the developer) is responsible for
     * pointing Dusk at a real app via DUSK_DRIVER_URL / APP_URL and a hosted
     * dev server. For local package work the skip path is the expected one.
     */
    protected function setUp(): void
    {
        if (! $this->chromeDriverReachable()) {
            $this->markTestSkipped(
                'ChromeDriver not running on http://localhost:9515. '
                .'Start it with: chromedriver --port=9515'
            );
        }

        parent::setUp();
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions())->addArguments([
            '--disable-gpu',
            '--headless=new',
            '--no-sandbox',
            '--window-size=1400,900',
        ]);

        try {
            return RemoteWebDriver::create(
                'http://localhost:9515',
                DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options)
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('ChromeDriver not running on http://localhost:9515. Start it with: chromedriver --port=9515');
        }
    }

    private function chromeDriverReachable(): bool
    {
        $fp = @fsockopen('127.0.0.1', 9515, $errno, $errstr, 0.5);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }
}
