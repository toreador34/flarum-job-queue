<?php

/**
 * This file is part of the Sjn4F Queue Manager extension for Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Sjn4F\QueueManager\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sjn4F\QueueManager\FailedJobsRepository;

abstract class AbstractQueueManagerController implements RequestHandlerInterface
{
    /**
     * @var FailedJobsRepository
     */
    protected $failedJobs;

    public function __construct(FailedJobsRepository $failedJobs)
    {
        $this->failedJobs = $failedJobs;
    }

    protected function requireAdmin(ServerRequestInterface $request): void
    {
        if (! RequestUtil::getActor($request)->isAdmin()) {
            throw new ModelNotFoundException();
        }
    }

    protected function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    protected function routeParam(ServerRequestInterface $request, string $key)
    {
        $query = $request->getQueryParams();

        return isset($query[$key]) ? $query[$key] : null;
    }
}