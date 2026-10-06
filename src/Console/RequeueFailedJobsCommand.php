<?php

/**
 * This file is part of the Toreador Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Toreador\QueueManager\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Toreador\QueueManager\FailedJobsRepository;
use Symfony\Component\Console\Input\InputArgument;

class RequeueFailedJobsCommand extends AbstractCommand
{
    /**
     * @var FailedJobsRepository
     */
    protected $failedJobs;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    public function __construct(FailedJobsRepository $failedJobs, SettingsRepositoryInterface $settings)
    {
        $this->failedJobs = $failedJobs;
        $this->settings = $settings;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('queue:failed-jobs:requeue')
            ->setDescription('Requeues failed database queue jobs. Without arguments it requeues every job that is eligible according to the extension settings (auto-requeue).')
            ->addArgument('ids', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Specific failed job IDs to requeue, e.g. `flarum queue:failed-jobs:requeue 12 45`.');
    }

    protected function fire()
    {
        $ids = $this->input->getArgument('ids');

        if (! empty($ids)) {
            $result = $this->failedJobs->requeueMany(array_map('intval', $ids));
        } else {
            if (! (bool) $this->settings->get('toreador-flarum-job-queue.auto_requeue', false)) {
                $this->info('Auto-requeue is disabled in the Queue Manager settings. Nothing to do (use the admin panel or pass explicit IDs).');

                return;
            }

            $minutes = (int) $this->settings->get('toreador-flarum-job-queue.auto_requeue_after', 5);
            $maxAttempts = (int) $this->settings->get('toreador-flarum-job-queue.auto_requeue_max_attempts', 1);
            $result = $this->failedJobs->requeueAll($minutes, null, $maxAttempts > 0 ? $maxAttempts : null);
        }

        $this->info('Requeued '.$result['requeued'].' failed job(s).');

        if (($result['skipped'] ?? 0) > 0) {
            $this->info('Skipped '.$result['skipped'].' failed job(s) that reached the auto-requeue limit; requeue them manually if needed.');
        }

        foreach ($result['errors'] as $id => $error) {
            $this->error('Failed job #'.$id.' could not be requeued: '.$error);
        }
    }
}