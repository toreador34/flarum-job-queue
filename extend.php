<?php

/**
 * This file is part of the Toreador Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Toreador\QueueManager;

use Flarum\Extend;
use Illuminate\Console\Scheduling\Event;
use Toreador\QueueManager\Api\Controller\ClearFailedJobsController;
use Toreador\QueueManager\Api\Controller\DeleteFailedJobController;
use Toreador\QueueManager\Api\Controller\ListFailedJobsController;
use Toreador\QueueManager\Api\Controller\RequeueAllFailedJobsController;
use Toreador\QueueManager\Api\Controller\RequeueFailedJobController;
use Toreador\QueueManager\Api\Controller\ShowFailedJobController;
use Toreador\QueueManager\Console\RequeueFailedJobsCommand;
use Toreador\QueueManager\Provider\QueueManagerServiceProvider;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/less/admin.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ServiceProvider())
        ->register(QueueManagerServiceProvider::class),

    (new Extend\Settings())
        ->default('toreador-flarum-job-queue.table_prefix', '')
        ->default('toreador-flarum-job-queue.auto_requeue', false)
        ->default('toreador-flarum-job-queue.auto_requeue_after', 5),

    (new Extend\Console())
        ->command(RequeueFailedJobsCommand::class)
        ->schedule(RequeueFailedJobsCommand::class, function (Event $event) {
            $event->everyMinute();
        }),

    (new Extend\Routes('api'))
        ->get('/queue-manager/jobs', 'toreador-flarum-job-queue.jobs', ListFailedJobsController::class)
        ->get('/queue-manager/jobs/{id}', 'toreador-flarum-job-queue.job', ShowFailedJobController::class)
        ->post('/queue-manager/jobs/requeue', 'toreador-flarum-job-queue.requeue-all', RequeueAllFailedJobsController::class)
        ->post('/queue-manager/jobs/clear', 'toreador-flarum-job-queue.clear', ClearFailedJobsController::class)
        ->post('/queue-manager/jobs/{id}/requeue', 'toreador-flarum-job-queue.requeue', RequeueFailedJobController::class)
        ->delete('/queue-manager/jobs/{id}', 'toreador-flarum-job-queue.delete', DeleteFailedJobController::class),
];