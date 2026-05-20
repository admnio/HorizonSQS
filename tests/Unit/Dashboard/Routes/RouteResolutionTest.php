<?php

namespace Admnio\Sunset\Tests\Unit\Dashboard\Routes;

use Admnio\Sunset\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class RouteResolutionTest extends TestCase
{
    #[DataProvider('routes')]
    public function test_route_is_registered(string $method, string $path): void
    {
        $found = false;
        foreach (Route::getRoutes() as $route) {
            if (in_array($method, $route->methods(), true) && $route->uri() === ltrim($path, '/')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Route {$method} {$path} should be registered");
    }

    public static function routes(): array
    {
        return [
            ['GET', 'sunset'],
            ['GET', 'sunset/workload'],
            ['GET', 'sunset/jobs/recent'],
            ['GET', 'sunset/jobs/failed'],
            ['GET', 'sunset/jobs/pending'],
            ['GET', 'sunset/jobs/completed'],
            ['GET', 'sunset/metrics'],
            ['GET', 'sunset/metrics/series'],
            ['GET', 'sunset/metrics/jobs/{name}'],
            ['GET', 'sunset/metrics/queues/{name}'],
            ['GET', 'sunset/monitoring'],
            ['GET', 'sunset/rate-limits'],
            ['GET', 'sunset/supervisors'],
            ['GET', 'sunset/batches'],
            ['GET', 'sunset/health'],
            ['POST', 'sunset/jobs/failed/{id}/retry'],
            ['POST', 'sunset/jobs/failed/retry'],
            ['POST', 'sunset/jobs/failed/{id}/delete'],
            ['POST', 'sunset/jobs/failed/delete'],
            ['POST', 'sunset/supervisors/{name}/pause'],
            ['POST', 'sunset/supervisors/{name}/resume'],
            ['POST', 'sunset/monitoring/{tag}/pin'],
            ['POST', 'sunset/monitoring/{tag}/unpin'],
        ];
    }
}
