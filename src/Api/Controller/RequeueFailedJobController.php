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

class RequeueFailedJobController extends AbstractQueueManagerController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireAdmin($request);

        $result = $this->failedJobs->requeue((int) $this->routeParam($request, 'id'));

        if (! $result['ok']) {
            return $this->json([
                'error' => $result['reason'] ?? 'error',
                'message' => isset($result['error']) ? $result['error'] : null,
            ], 404);
        }

        return $this->json(['ok' => true, 'requeued' => true, 'id' => $result['id']]);
    }
}