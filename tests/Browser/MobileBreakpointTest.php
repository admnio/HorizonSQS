<?php

namespace Admnio\Sunset\Tests\Browser;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\DuskTestCase;
use Laravel\Dusk\Browser;

class MobileBreakpointTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);
    }

    public function test_left_rail_hidden_at_mobile_width(): void
    {
        $this->browse(function (Browser $b) {
            $b->resize(375, 700)
              ->visit('/sunset')
              ->waitForText('Overview', 5);

            // LeftRail has class "hidden md:block" — should NOT be visible at 375px.
            $b->assertNotVisible('nav.w-\\[160px\\]');
        });
    }
}
