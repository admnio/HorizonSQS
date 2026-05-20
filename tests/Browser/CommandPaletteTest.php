<?php

namespace Admnio\Sunset\Tests\Browser;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\DuskTestCase;
use Laravel\Dusk\Browser;

class CommandPaletteTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);
    }

    public function test_cmd_k_opens_palette_and_enter_navigates(): void
    {
        $this->browse(function (Browser $b) {
            $b->visit('/sunset')
              ->waitForText('Overview', 5)
              ->keys('body', ['{ctrl}', 'k'])
              ->waitFor('input[placeholder="Jump to page…"]', 3)
              ->type('input[placeholder="Jump to page…"]', 'fail')
              ->pause(200)
              ->keys('input[placeholder="Jump to page…"]', '{enter}')
              ->waitForLocation('/sunset/jobs/failed', 5);
        });
    }
}
