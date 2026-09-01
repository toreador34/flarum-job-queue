<?php

/**
 * This file is part of the Sjn4F Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Sjn4F\QueueManager\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Sjn4F\QueueManager\FailedJobsRepository;
use Sjn4F\QueueManager\JobClassMap;
use Sjn4F\QueueManager\Queue\QueueTables;

class QueueManagerServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->singleton(QueueTables::class, function (Container $container) {
            $settings = $container->make(SettingsRepositoryInterface::class);
            $override = (string) $settings->get('toreador-flarum-job-queue.table_prefix', '');

            return QueueTables::fromSettings($container->make('flarum.db'), $override);
        });

        $this->container->singleton(JobClassMap::class);

        $this->container->singleton(FailedJobsRepository::class, function (Container $container) {
            return new FailedJobsRepository(
                $container->make('flarum.db'),
                $container->make(QueueTables::class),
                $container->make(JobClassMap::class),
                $container->has('log') ? $container->make('log') : null
            );
        });
    }
}