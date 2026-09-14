<?php

/**
 * This file is part of the Toreador Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Toreador\QueueManager\Api\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RequeueAllFailedJobsController extends AbstractQueueManagerController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireAdmin($request);

        $body = (array) $request->getParsedBody();
        $queue = isset($body['queue']) ? (string) $body['queue'] : null;

        $result = $this->failedJobs->requeueAll(0, $queue ?: null);

        return $this->json([
            'ok' => true,
            'requeued' => $result['requeued'],
            'failed_ids' => $result['failed_ids'],
            'errors' => $result['errors'],
        ]);
    }
}