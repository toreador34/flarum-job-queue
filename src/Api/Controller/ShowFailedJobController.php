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

class ShowFailedJobController extends AbstractQueueManagerController
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireAdmin($request);

        $detail = $this->failedJobs->detail((int) $this->routeParam($request, 'id'));

        if (! $detail) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json(['data' => $detail]);
    }
}