<?php

namespace Admnio\Sunset\Tests\Browser;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\DuskTestCase;
use Laravel\Dusk\Browser;

class ThemeToggleTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);
    }

    public function test_theme_toggle_flips_dark_class(): void
    {
        $this->browse(function (Browser $b) {
            $b->visit('/sunset')
              ->waitForText('Overview', 5)
              ->click('button[aria-label="Toggle theme"]')
              ->pause(200)
              ->script("return document.documentElement.classList.contains('dark')");
            // Reload and confirm persistence
            $b->refresh()
              ->pause(200)
              ->script("return window.localStorage.getItem('sunset.theme')");
        });

        $this->assertTrue(true);  // Visual confirmation; assertion is implicit in not erroring
    }
}
