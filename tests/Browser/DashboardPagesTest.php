<?php

namespace Admnio\Sunset\Tests\Browser;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\DuskTestCase;
use Laravel\Dusk\Browser;

class DashboardPagesTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);
    }

    /**
     * @dataProvider pages
     */
    public function test_page_renders(string $path, string $expectText): void
    {
        $this->browse(function (Browser $b) use ($path, $expectText) {
            $b->visit($path)->waitForText($expectText, 5);
        });
    }

    public static function pages(): array
    {
        return [
            ['/sunset',                'Overview'],
            ['/sunset/workload',       'Workload'],
            ['/sunset/jobs/recent',    'Recent'],
            ['/sunset/jobs/failed',    'Failed'],
            ['/sunset/jobs/pending',   'Pending'],
            ['/sunset/jobs/completed', 'Completed'],
            ['/sunset/metrics',        'Metrics'],
            ['/sunset/monitoring',     'Monitoring'],
            ['/sunset/rate-limits',    'Rate limits'],
            ['/sunset/supervisors',    'Supervisors'],
            ['/sunset/batches',        'Batches'],
            ['/sunset/health',         'Health'],
        ];
    }
}
