<?php

use Admnio\Sunset\Dashboard\Http\Controllers as C;
use Admnio\Sunset\Dashboard\Http\Middleware\Authorize;
use Admnio\Sunset\Dashboard\Http\Middleware\SetSunsetInertiaRoot;
use Illuminate\Support\Facades\Route;

Route::middleware([Authorize::class, \Inertia\Middleware::class, SetSunsetInertiaRoot::class])
    ->prefix(config('sunset.dashboard.path', config('sunset.path', 'sunset')))
    ->name('sunset.')
    ->group(function () {
        // GET pages — every controller's show() returns Inertia OR same-route JSON.
        Route::get('/',                       [C\OverviewController::class,      'show'])->name('overview');
        Route::get('/workload',               [C\WorkloadController::class,      'show'])->name('workload');
        Route::get('/jobs/recent',            [C\RecentJobsController::class,    'show'])->name('jobs.recent');
        Route::get('/jobs/failed',            [C\FailedJobsController::class,    'show'])->name('jobs.failed');
        Route::get('/jobs/pending',           [C\PendingJobsController::class,   'show'])->name('jobs.pending');
        Route::get('/jobs/completed',         [C\CompletedJobsController::class, 'show'])->name('jobs.completed');
        Route::get('/metrics',                [C\MetricsController::class,       'show'])->name('metrics');
        Route::get('/metrics/series',         [C\MetricsController::class,       'series'])->name('metrics.series');
        Route::get('/metrics/jobs/{name}',    [C\MetricsController::class,       'jobSeries'])->name('metrics.jobs');
        Route::get('/metrics/queues/{name}',  [C\MetricsController::class,       'queueSeries'])->name('metrics.queues');
        // v2.0: Inertia detail page for a single job class. Distinct from the
        // existing jobSeries JSON endpoint above.
        Route::get('/metrics/jobs/{name}/detail', [C\MetricsController::class,   'class'])
            ->where('name', '[^/]+')
            ->name('metrics.jobs.detail');
        Route::get('/monitoring',             [C\MonitoringController::class,    'show'])->name('monitoring');
        // v2.0: Inertia detail page for a single monitored tag.
        Route::get('/monitoring/tags/{tag}',  [C\MonitoringController::class,    'tag'])
            ->where('tag', '[^/]+')
            ->name('monitoring.tags.detail');
        Route::get('/rate-limits',            [C\RateLimitsController::class,    'show'])->name('rate-limits');
        Route::get('/supervisors',            [C\SupervisorsController::class,   'show'])->name('supervisors');
        Route::get('/batches',                [C\BatchesController::class,       'show'])->name('batches');
        Route::get('/health',                 [C\HealthController::class,        'show'])->name('health');

        // v1.2.0 (polling-only since v1.2.1): Activity log — Inertia page +
        // same-route JSON polling, plus a "load older" pagination endpoint.
        // The recorder behind these reads sunset.activity.enabled internally;
        // when disabled, both endpoints return an empty events list.
        Route::get('/activity',         [C\ActivityController::class, 'show'])->name('activity');
        Route::get('/activity/page',    [C\ActivityController::class, 'page'])->name('activity.page');

        // POST actions — write through to native repositories.
        Route::post('/jobs/failed/{id}/retry',    [C\FailedJobsController::class,  'retry'])->name('jobs.failed.retry');
        Route::post('/jobs/failed/retry',         [C\FailedJobsController::class,  'retryBulk'])->name('jobs.failed.retry-bulk');
        Route::post('/jobs/failed/{id}/delete',   [C\FailedJobsController::class,  'delete'])->name('jobs.failed.delete');
        Route::post('/jobs/failed/delete',        [C\FailedJobsController::class,  'deleteBulk'])->name('jobs.failed.delete-bulk');
        Route::post('/supervisors/{name}/pause',  [C\SupervisorsController::class, 'pause'])->name('supervisors.pause');
        Route::post('/supervisors/{name}/resume', [C\SupervisorsController::class, 'resume'])->name('supervisors.resume');
        // v2.3.0: live worker scaling — operator clicks +/− on the dashboard
        // and the supervisor's next loop tick adjusts its worker count without
        // restarting. The `where` constraint mirrors the other supervisor
        // routes so names with `:` / `.` characters route cleanly.
        Route::post('/supervisors/{name}/scale',  [C\SupervisorsController::class, 'scale'])
            ->where('name', '[^/]+')
            ->name('supervisors.scale');
        // v1.3.0: per-queue pause/resume. The `where` constraints allow any
        // non-slash characters in queue names (queue names commonly include
        // `-`, `_`, `.`, `:` — e.g. SQS allows `.fifo` suffixes; apps may
        // namespace queues like `tenant:foo`).
        Route::post('/workload/{connection}/{queue}/pause',  [C\WorkloadController::class, 'pause'])
            ->where(['connection' => '[^/]+', 'queue' => '[^/]+'])
            ->name('workload.pause');
        Route::post('/workload/{connection}/{queue}/resume', [C\WorkloadController::class, 'resume'])
            ->where(['connection' => '[^/]+', 'queue' => '[^/]+'])
            ->name('workload.resume');
        Route::post('/monitoring/{tag}/pin',      [C\MonitoringController::class,  'pin'])->name('monitoring.pin');
        Route::post('/monitoring/{tag}/unpin',    [C\MonitoringController::class,  'unpin'])->name('monitoring.unpin');
    });
