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

class ClearFailedJobsController extends AbstractQueueManagerController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireAdmin($request);

        $body = (array) $request->getParsedBody();
        $queue = isset($body['queue']) ? (string) $body['queue'] : null;

        $deleted = $this->failedJobs->clear($queue ?: null);

        return $this->json([
            'ok' => true,
            'deleted' => $deleted,
        ]);
    }
}